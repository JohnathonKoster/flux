<?php

namespace Flux\Compiler;

use Illuminate\Support\Str;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Stillat\BladeParser\Nodes\Components\ComponentNode;
use Stillat\BladeParser\Parser\DocumentParser;

class TagCompiler extends ComponentTagCompiler
{
    public function compile($value)
    {
        if (! $value) {
            return $value;
        }

        return (new DocumentParser)
            ->registerCustomComponentTag('flux')
            ->onlyParseComponents()
            ->parseTemplate($value)
            ->toDocument()
            ->getRootNodes()
            ->map(fn ($node) => $this->compileNode($node))
            ->join('');
    }

    protected function isFluxComponent(ComponentNode $node)
    {
        return mb_strtolower($node->componentPrefix) === 'flux';
    }

    protected function isSlot($node)
    {
        return $node instanceof ComponentNode && mb_strtolower($node->tagName) === 'slot';
    }

    protected function compileFluxSlot(ComponentNode $node)
    {
        $attributes = $this->getAttributesFromAttributeString($node->parameterContent);

        $hasNameAttribute = $node->hasParameter('name');
        $hasInlineName = mb_strtolower($node->name) !== 'slot'; // The "name" will contain the slot: prefix.
        $name = null;

        if ($hasInlineName) {
            $name = str($node->name)
                ->after('slot:') // Skip the slot prefix
                ->camel()
                ->wrap("'", "'")
                ->value();
        } else {
            $nameAttribute = $node->getParameter('name');
            unset($attributes['name']); // Goodbye.

            $name = $nameAttribute->valueNode->content; // This will contain the original quotes.
        }

        return " @slot({$name}, null, [".$this->attributesToString($attributes).']) '.
            $this->compileChildNodes($node).
            " @endslot";
    }

    protected function compileChildNodes(ComponentNode $node)
    {
        $isFluxComponent = $this->isFluxComponent($node);

        return collect($node->childNodes ?? [])
            ->map(function ($node) use ($isFluxComponent) {
                if ($isFluxComponent && $this->isSlot($node)) {
                    return $this->compileFluxSlot($node);
                }

                return $this->compileNode($node);
            })
            ->join('');
    }

    protected function compileBladeComponent(ComponentNode $node)
    {
        if ($node->isSelfClosing) {
            return $node->content;
        }

        return $node->content.
            $this->compileChildNodes($node).
            $node->isClosedBy?->content ?? '';
    }

    protected function compileNode($node)
    {
        if (! $node instanceof ComponentNode) {
            return $node->unescapedContent;
        }

        if ($node->componentPrefix != 'flux') {
            return $this->compileBladeComponent($node);
        }

        if ($node->isClosingTag && ! $node->isSelfClosing) {
            return '';
        }

        if ($node->isSelfClosing) {
            return $this->compileSelfClosingTag($node);
        }

        return $this->compileFluxTag($node);
    }

    private function fluxComponentString(ComponentNode $node, $attributes = null)
    {
        if ($attributes === null) {
            $attributes = $this->getAttributesFromAttributeString($node->parameterContent);
        }

        return $this->componentString(
            'flux::'.$node->name,
            $attributes
        );
    }

    protected function compileFluxTag(ComponentNode $node)
    {
        if ($node->name ===  'delegate-component') {
            $attributes = $this->getAttributesFromAttributeString($node->parameterContent);
            $component = $attributes['component'];
            $class = \Illuminate\View\AnonymousComponent::class;

            // Laravel 12+ uses xxh128 hashing for views https://github.com/laravel/framework/pull/52301...
            return "<?php if (!Flux::componentExists(\$name = {$component})) throw new \Exception(\"Flux component [{\$name}] does not exist.\"); ?>##BEGIN-COMPONENT-CLASS##@component('{$class}', 'flux::' . {$component}, [
    'view' => (app()->version() >= 12 ? hash('xxh128', 'flux') : md5('flux')) . '::' . {$component},
    'data' => \$__env->getCurrentComponentData(),
])
<?php \$component->withAttributes(\$attributes->getAttributes()); ?>".$this->compileChildNodes($node).$this->compileClosingTag($node->isClosedBy);
        }

        return $this->fluxComponentString($node).$this->compileChildNodes($node).$this->compileClosingTag($node->isClosedBy);
    }

    protected function compileSelfClosingTag(ComponentNode $node)
    {
        $attributes = $this->getAttributesFromAttributeString($node->parameterContent);

        // TODO: We will have to hoist these out later once we manage slots separately.
        if (isset($attributes['slot'])) {
            $slot = $attributes['slot'];

            unset($attributes['slot']);

            return '@slot('.$slot.') ' . $this->fluxComponentString($node, $attributes)."\n@endComponentClass##END-COMPONENT-CLASS##" . ' @endslot';
        }

        return $this->fluxComponentString($node)."\n@endComponentClass##END-COMPONENT-CLASS##";
    }

    protected function compileClosingTag(ComponentNode $node)
    {
        return ' @endComponentClass##END-COMPONENT-CLASS##';
    }
}
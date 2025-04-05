<?php

namespace Flux\Compiler;

use Egulias\EmailValidator\Result\Reason\AtextAfterCFWS;
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
            ->map(fn ($node) => $this->compileNode($node))->join('');
    }

    protected function compileChildNodes(array $nodes)
    {
        return collect($nodes)
            ->map(fn($node) => $this->compileNode($node))
            ->join('');
    }

    protected function compileNode($node)
    {
        if (! $node instanceof ComponentNode) {
            return $node->unescapedContent;
        }

        if ($node->componentPrefix != 'flux') {
            return $node->outerDocumentContent;
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

    protected function compileInnerContent(ComponentNode $node)
    {
        $innerContent = $node->innerDocumentContent;

        if (Str::contains($innerContent, ['<flux:', '<flux-'])) {
            $innerContent = $this->compileChildNodes($node->childNodes);
        }

        return $innerContent;
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
<?php \$component->withAttributes(\$attributes->getAttributes()); ?>".$this->compileInnerContent($node).$this->compileClosingTag($node->isClosedBy);
        }

        return $this->fluxComponentString($node).$this->compileInnerContent($node).$this->compileClosingTag($node->isClosedBy);
    }

    protected function compileSelfClosingTag(ComponentNode $node)
    {
        $attributes = $this->getAttributesFromAttributeString($node->parameterContent);

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
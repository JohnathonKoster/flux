<?php

namespace Flux\Compiler;

use Illuminate\Support\Str;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Illuminate\View\DynamicComponent;
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

    protected function compileFluxComponent($expression)
    {
        [$component, $alias, $data] = str_contains($expression, ',')
            ? array_map(trim(...), explode(',', trim($expression, '()'), 3)) + ['', '', '']
            : [trim($expression, '()'), '', ''];

        $component = trim($component, '\'"');

        $hash = ComponentHashes::newComponentHash(
            $component === AnonymousComponent::class ? $component.':'.trim($alias, '\'"') : $component
        );

        if (Str::contains($component, ['::class', '\\'])) {
            return BladeCompiler::compileClassComponentOpening($component, $alias, $data, $hash);
        }

        return "<?php \$__env->startComponent{$expression}; ?>";
    }

    private function fluxComponentString(ComponentNode $node, $attributes = null)
    {
        if ($attributes === null) {
            $attributes = $this->getAttributesFromAttributeString($node->parameterContent);
        }

        $component = 'flux::'.$node->name;

        $class = $this->componentClass($component);

        [$data, $attributes] = $this->partitionDataAndAttributes($class, $attributes);

        $data = $data->mapWithKeys(function ($value, $key) {
            return [Str::camel($key) => $value];
        });

        // If the component doesn't exist as a class, we'll assume it's a class-less
        // component and pass the component as a view parameter to the data so it
        // can be accessed within the component and we can render out the view.
        if (! class_exists($class)) {
            $view = Str::startsWith($component, 'mail::')
                ? "\$__env->getContainer()->make(Illuminate\\View\\Factory::class)->make('{$component}')"
                : "'$class'";

            $parameters = [
                'view' => $view,
                'data' => '['.$this->attributesToString($data->all(), $escapeBound = false).']',
            ];

            $class = AnonymousComponent::class;
        } else {
            $parameters = $data->all();
        }

        $compiledComponent = $this->compileFluxComponent("'{$class}', '{$component}', [".$this->attributesToString($parameters, $escapeBound = false).']');

        return "##BEGIN-COMPONENT-CLASS##{$compiledComponent}".'
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\\'.$class.'::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['.$this->attributesToString($attributes->all(), $escapeAttributes = $class !== DynamicComponent::class).']); ?>';
    }

    protected function compileFluxTag(ComponentNode $node)
    {
        if ($node->name ===  'delegate-component') {
            $attributes = $this->getAttributesFromAttributeString($node->parameterContent);
            $component = $attributes['component'];
            $class = \Illuminate\View\AnonymousComponent::class;

            // Laravel 12+ uses xxh128 hashing for views https://github.com/laravel/framework/pull/52301...
            $expression = "'{$class}', 'flux::' . {$component}, [
    'view' => (app()->version() >= 12 ? hash('xxh128', 'flux') : md5('flux')) . '::' . {$component},
    'data' => \$__env->getCurrentComponentData(),
]";
            $compiledComponent = $this->compileFluxComponent($expression);

            return "<?php if (!Flux::componentExists(\$name = {$component})) throw new \Exception(\"Flux component [{\$name}] does not exist.\"); ?>##BEGIN-COMPONENT-CLASS##{$compiledComponent}
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

            return '@slot('.$slot.') ' . $this->fluxComponentString($node, $attributes)."\n".$this->compileEndComponentClass()."##END-COMPONENT-CLASS##" . ' @endslot';
        }

        return $this->fluxComponentString($node)."\n".$this->compileEndComponentClass()."##END-COMPONENT-CLASS##";
    }

    protected function compileClosingTag(ComponentNode $node)
    {
        return ' '.$this->compileEndComponentClass().'##END-COMPONENT-CLASS##';
    }

    protected function compileEndComponent()
    {
        return '<?php echo $__env->renderComponent(); ?>';
    }

    protected function compileEndComponentClass()
    {
        $hash = ComponentHashes::popHash();

        return $this->compileEndComponent()."\n".implode("\n", [
                '<?php endif; ?>',
                '<?php if (isset($__attributesOriginal'.$hash.')): ?>',
                '<?php $attributes = $__attributesOriginal'.$hash.'; ?>',
                '<?php unset($__attributesOriginal'.$hash.'); ?>',
                '<?php endif; ?>',
                '<?php if (isset($__componentOriginal'.$hash.')): ?>',
                '<?php $component = $__componentOriginal'.$hash.'; ?>',
                '<?php unset($__componentOriginal'.$hash.'); ?>',
                '<?php endif; ?>',
            ]);
    }
}
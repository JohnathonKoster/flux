<?php

namespace Flux\Compiler;

use Egulias\EmailValidator\Result\Reason\AtextAfterCFWS;
use Flux\Compiler\Concerns\CompilesClosingTags;
use Flux\Compiler\Concerns\CompilesFluxComponentDirectives;
use Flux\Compiler\Concerns\CompilesInnerContent;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Stillat\BladeParser\Nodes\Components\ComponentNode;
use Stillat\BladeParser\Parser\DocumentParser;

class TagCompiler extends ComponentTagCompiler
{
    use CompilesFluxComponentDirectives,
        CompilesInnerContent,
        CompilesClosingTags;

    protected array $nodes = [];

    public function compile($value)
    {
        $this->nodes = [];

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

        $this->nodes[$node->id] = $node;

        $componentString = $this->componentString('flux::'.$node->name, $attributes);
        $lines = explode("\n", $componentString);

        $first = Str::after($lines[0], '##BEGIN-COMPONENT-CLASS##');
        $first = mb_substr($first, 11, -1);

        $first = '##BEGIN-COMPONENT-CLASS##'.$this->compileFluxComponent($node->id, $first);
        $lines[0] = $first;

        return implode("\n", $lines);
    }

    protected function compileFluxTag(ComponentNode $node)
    {
        $innerContent = $this->compileInnerContent($node);

        if ($node->name ===  'delegate-component') {
            $attributes = $this->getAttributesFromAttributeString($node->parameterContent);
            $component = $attributes['component'];
            $class = \Illuminate\View\AnonymousComponent::class;

            $expression = "'{$class}', 'flux::' . {$component}, [
    'view' => (app()->version() >= 12 ? hash('xxh128', 'flux') : md5('flux')) . '::' . {$component},
    'data' => \$__env->getCurrentComponentData(),
]";

            $leading = "<?php if (!Flux::componentExists(\$name = {$component})) throw new \Exception(\"Flux component [{\$name}] does not exist.\"); ?>";
            $compiledOpen = '##BEGIN-COMPONENT-CLASS##'.$this->compileFluxComponent($node->id, $expression);
            $compiledEnd = $this->compileClosingTag($node->isClosedBy);

            return $leading.$compiledOpen.$innerContent.$compiledEnd;
            ray($leading.$compiledOpen.$innerContent.$compiledEnd);

            dd('hm');

            // Laravel 12+ uses xxh128 hashing for views https://github.com/laravel/framework/pull/52301...
            return "<?php if (!Flux::componentExists(\$name = {$component})) throw new \Exception(\"Flux component [{\$name}] does not exist.\"); ?>##BEGIN-COMPONENT-CLASS##@component('{$class}', 'flux::' . {$component}, [
    'view' => (app()->version() >= 12 ? hash('xxh128', 'flux') : md5('flux')) . '::' . {$component},
    'data' => \$__env->getCurrentComponentData(),
])
<?php \$component->withAttributes(\$attributes->getAttributes()); ?>" . $innerContent . '/** THE DELEGATE COMPONENT IDEA */' . $this->compileClosingTag($node->isClosedBy);
        }

        return $this->fluxComponentString($node) . $innerContent . $this->compileClosingTag($node->isClosedBy);
    }
}
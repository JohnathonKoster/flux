<?php

namespace Flux\Compiler\Concerns;

use Stillat\BladeParser\Nodes\Components\ComponentNode;

trait CompilesClosingTags
{
    protected function compileSelfClosingTag(ComponentNode $node)
    {
        $attributes = $this->getAttributesFromAttributeString($node->parameterContent);

        if (isset($attributes['slot'])) {
            $slot = $attributes['slot'];

            unset($attributes['slot']);

            return '@slot('.$slot.') ' . $this->fluxComponentString($node, $attributes) . "\n" . $this->compileEndFluxComponentClass($node->id) . '##END-COMPONENT-CLASS## @endslot';
        }

        return $this->fluxComponentString($node) . "\n".$this->compileEndFluxComponentClass($node->id) . "##END-COMPONENT-CLASS##";
    }

    protected function compileClosingTag(ComponentNode $node)
    {
        //if ($node->name === 'delegate-component') {
        //    return ' @endComponentClass##END-COMPONENT-CLASS##';
        //}

        return $this->compileEndFluxComponentClass($node->isOpenedBy->id) . '##END-COMPONENT-CLASS##';
    }
}
<?php

namespace Flux\Compiler\Concerns;

use Illuminate\Support\Str;
use Stillat\BladeParser\Nodes\Components\ComponentNode;

trait CompilesInnerContent
{
    protected $slotContents = [];

    protected function compileInnerContent(ComponentNode $node)
    {
        $innerContent = $this->compileChildNodes($node->childNodes);
        $replacement = '__FLUX::SLOT::'.md5(mb_strtolower($node->innerContent));

        $this->slotContents[$node->id] = [
            $replacement,
            $innerContent
        ];

        return $replacement;
    }
}
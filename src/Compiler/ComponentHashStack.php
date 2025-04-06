<?php

namespace Flux\Compiler;

use Illuminate\View\Compilers\BladeCompiler;

class ComponentHashStack extends BladeCompiler
{
    public static function pop()
    {
        return array_pop(static::$componentHashStack);
    }

    public static function newComponentHash($component)
    {
        return parent::newComponentHash($component);
    }
}
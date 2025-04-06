<?php

namespace Flux\Compiler\Concerns;

use Flux\Compiler\ComponentHashStack;
use Illuminate\Support\Str;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;

trait CompilesFluxComponentDirectives
{
    public function compileFluxComponent($node, $expression)
    {
        $slotContent = null;

        if (isset($this->slotContents[$node])) {
            $slotContent = $this->slotContents[$node];
        }

        [$component, $alias, $data] = str_contains($expression, ',')
            ? array_map(trim(...), explode(',', trim($expression, '()'), 3)) + ['', '', '']
            : [trim($expression, '()'), '', ''];

        $component = trim($component, '\'"');

        $hash = ComponentHashStack::newComponentHash(
            $component === AnonymousComponent::class ? $component.':'.trim($alias, '\'"') : $component
        );

        if (Str::contains($component, ['::class', '\\'])) {
            $hoistedVar = '$__fluxHoistedData'.$hash;
            $fluxCacheKey = '$__fluxAttributeCacheKey'.$hash;
            $result = "<?php {$hoistedVar} = {$data}; {$fluxCacheKey} = \Flux\Flux::cache()->key({$hoistedVar}); ?>";
            $stub = <<<'PHP'
<?php if (\Flux\Flux::cache()->has($__fluxAttributeCacheKeyHash)):
    $__fluxRetrievedValueHash = \Flux\Flux::cache()->get($__fluxHoistedDataHash['view'], $__fluxAttributeCacheKeyHash, $__fluxHoistedDataHash);
?>#slotReplacement#<?php 
    echo $__fluxRetrievedValueHash;
    unset($__fluxRetrievedValueHash);
else: ?>
PHP;

            $cachedSlot = '';

            if ($slotContent != null) {

                $cachedSlot = <<<'PHP'
<?php ob_start(); ?>#slot#<?php $__fluxRetrievedValueHash = str_replace('$replacement', ob_get_clean(), $__fluxRetrievedValueHash); ?>
PHP;

                $cachedSlot = Str::swap([
                    '#slot#' => $slotContent[1],
                    '$replacement' => $slotContent[0],
                ], $cachedSlot);

            }

            $stub = str_replace('#slotReplacement#', $cachedSlot, $stub);

            $result .= str_replace('Hash', $hash, $stub);
            $result .= BladeCompiler::compileClassComponentOpening($component, $alias, $hoistedVar, $hash);

            return $result;
        }

        return "<?php \$__env->startComponent{$expression}; ?>";
    }

    public function compileEndFluxComponentClass($expression)
    {
        $hash = ComponentHashStack::pop();
        $slotContent = null;

        if (isset($this->slotContents[$expression])) {
            $slotContent = $this->slotContents[$expression];
        }

        $endComponent = <<<'PHP'
<?php
    \Flux\Flux::cache()->startObserving();
    $__fluxTmpOutputHash = $__env->renderComponent();
    \Flux\Flux::cache()->stopObserving();
    $__fluxAttributeCacheKeyHash = \Flux\Flux::cache()->key($__fluxHoistedDataHash);
    \Flux\Flux::cache()->put($__fluxAttributeCacheKeyHash, $__fluxTmpOutputHash);
?>#endComponentSlotReplacement#<?php
    // TODO: There is a bug where the first swap would not be replaced.
    //       This can be fixed with a post-render callback, or by
    //       injecting some strings to take remove these later,
    //       but not a huge blocker for now while validating       
    echo $__fluxTmpOutputHash;
    unset($__fluxTmpOutputHash);
?>
PHP;

        $cachedSlot = '';
        $endComponent = str_replace('Hash', $hash, $endComponent);

        if ($slotContent != null) {
            $cachedSlot = <<<'PHP'
<?php ob_start(); ?>#slot#<?php $__fluxTmpOutputHash = str_replace('$replacement', ob_get_clean(), $__fluxTmpOutputHash); ?>
PHP;

            $cachedSlot = Str::swap([
                '#slot#' => $slotContent[1],
                '$replacement' => $slotContent[0],
                'Hash' => $hash,
            ], $cachedSlot);
        }

        $endComponent = str_replace('#endComponentSlotReplacement#', $cachedSlot, $endComponent);

        return $endComponent."\n".implode("\n", [
                '<?php endif; ?>',
                '<?php if (isset($__attributesOriginal'.$hash.')): ?>',
                '<?php $attributes = $__attributesOriginal'.$hash.'; ?>',
                '<?php unset($__attributesOriginal'.$hash.'); ?>',
                '<?php endif; ?>',
                '<?php if (isset($__componentOriginal'.$hash.')): ?>',
                '<?php $component = $__componentOriginal'.$hash.'; ?>',
                '<?php unset($__componentOriginal'.$hash.'); ?>',
                '<?php endif; ?>',
                '<?php endif; ?>',
                '<?php unset($__fluxHoistedData'.$hash.'); ?>',
                '<?php unset($__fluxAttributeCacheKey'.$hash.'); ?>',
            ]);
    }
}
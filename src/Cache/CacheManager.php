<?php

namespace Flux\Cache;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;

class CacheManager
{
    protected $enabled = true;

    protected $trap  = false;

    protected $items = [];

    protected $observedComponents = [];

    protected $observationStack = [];

    protected $swaps = [];

    public function swap($value, $callback)
    {
        $details = $this->observationStack[array_key_last($this->observationStack)];
        $componentName = $details['component'];

        if (! array_key_exists($componentName, $this->swaps)) {
            $this->swaps[$componentName] = [];
        }

        $replacement = '__FLUX::SWAP'.Str::random();

        $this->swaps[$componentName][$value] = [
            $replacement,
            $callback
        ];

        return $replacement;
    }

    public function ignore($keys)
    {
        $keys = Arr::wrap($keys);

        $this->observationStack[array_key_last($this->observationStack)]['ignore'] = $keys;
    }

    public function startObserving()
    {
        $this->observationStack[] = [
            'component' => app('view')->getCurrentComponentForFlux(),
        ];
    }

    public function stopObserving()
    {
        $lastObserved = array_pop($this->observationStack);

        $this->observedComponents[$lastObserved['component']] = $lastObserved;
    }

    public function key($data)
    {
        if (! $this->enabled) {
            return null;
        }

        $componentName = $data['view'];

        if (! array_key_exists($componentName, $this->observedComponents)) {
            return null;
        }

        $observed = $this->observedComponents[$componentName];
        $ignoreKeys = array_flip($observed['ignore'] ?? []);
        $ignoreKeys['__laravel_slots'] = 1;

        $data = $data['data'] ?? [];

        ksort($data);

        $cacheKey = $componentName.'|';

        foreach ($data as $key => $value) {
            if (array_key_exists($key, $ignoreKeys)) {
                continue;
            }

            if (is_object($value)) {
                if ($value instanceof ComponentAttributeBag) {
                    $cacheKey .= $key.'|'.$value->toHtml();

                    continue;
                }

                if ($value instanceof ComponentSlot) {
                    continue;
                }

                return null;
            }

            $cacheKey .= $key.'|'.$value;
        }

        return md5($cacheKey);
    }

    public function put($key, $value)
    {
        if (! $this->enabled) {
            return;
        }

        $this->items[$key] = $value;
    }

    public function has($key)
    {
        if (! $this->enabled) {
            return false;
        }

        if (! $key) {
            return false;
        }

        return array_key_exists($key, $this->items);
    }

    public function processAnonymousSwaps($value, $data)
    {
        $data = $data['data'] ?? [];

        foreach ($this->swaps as $viewSwaps) {
            foreach ($viewSwaps as $valueName => $theSwap) {
                if (str_contains($value, $theSwap[0])) {
                    $value = str_replace(
                        $theSwap[0],
                        $theSwap[1]($data[$valueName]),
                        $value
                    );
                }
            }
        }

        return $value;
    }

    public function get($view, $key, $data)
    {
        if (! $this->enabled) {
            return null;
        }

        $value = $this->items[$key];

        if (! array_key_exists($view, $this->swaps)) {
            return $this->processAnonymousSwaps($value, $data);
        }

        $data = $data['data'] ?? [];
        $swaps = $this->swaps[$view];

        foreach ($swaps as $valueName => $theSwap) {
            $value = str_replace(
                $theSwap[0],
                $theSwap[1]($data[$valueName]),
                $value
            );
        }

        return $value;
    }
}
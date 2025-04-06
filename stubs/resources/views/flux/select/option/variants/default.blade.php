@php
    \Flux\Flux::cache()->ignore('value');
@endphp

@props([
    'value' => null,
])

<option
        {{ $attributes }}
        {!!
            \Flux\Flux::cache()->swap('value', function ($value) {
                if (! isset($value)) { return ''; }

                $value = e($value);

                return implode(' ', [
                    "value='{$value}'",
                    "wire:key='{$value}'"
                ]);
            })
        !!}
>{{ $slot }}</option>
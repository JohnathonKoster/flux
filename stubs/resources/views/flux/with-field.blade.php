@php
extract(Flux::forwardedAttributes($attributes, [
    'name',
    'descriptionTrailing',
    'description',
    'label',
    'badge',
]));
@endphp

@php $descriptionTrailing = $descriptionTrailing ??= $attributes->pluck('description:trailing'); @endphp

@props([
    'name' => $attributes->whereStartsWith('wire:model')->first(),
    'descriptionTrailing' => null,
    'description' => null,
    'label' => null,
    'badge' => null,
])

<?php if ($label || $description): ?>
    <flux:field :attributes="Flux::attributesAfter('field:', $attributes, [])">
        <?php if ($label): ?>
            <flux:label :attributes="Flux::attributesAfter('label:', $attributes, ['badge' => $badge])">{{ $label }}</flux:label>
        <?php endif; ?>

        <?php if ($description): ?>
            <flux:description :attributes="Flux::attributesAfter('description:', $attributes, [])">{{ $description }}</flux:description>
        <?php endif; ?>

        {{ $slot }}

        <flux:error :attributes="Flux::attributesAfter('error:', $attributes, ['name' => $name])" />

        <?php if ($descriptionTrailing): ?>
            <flux:description :attributes="Flux::attributesAfter('description:', $attributes, [])">{{ $descriptionTrailing }}</flux:description>
        <?php endif; ?>
    </flux:field>
<?php else: ?>
    {{ $slot }}
<?php endif; ?>

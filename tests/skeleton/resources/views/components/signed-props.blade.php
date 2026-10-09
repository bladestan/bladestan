@php
    /**
     * @bladestan-signature
     * @var string $title
     * @var string $price
     */
@endphp
@props(['title', 'price'])
<div {{ $attributes->merge(['class' => 'card']) }}>
    <strong>{{ $title }}</strong>
    <span>{{ $price }}</span>
    {{ $slot }}
</div>

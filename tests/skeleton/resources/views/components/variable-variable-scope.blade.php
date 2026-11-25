@php
    /**
     * @bladestan-signature
     * @var string $title
     * @var string $color
     * @var string $field
     */
@endphp
@aware(['color'])
@props(['title'])
@php
    foreach (['a'] as $name) {
        $$name = $field;
    }
@endphp
<strong class="{{ $color }}">{{ $title }}</strong>

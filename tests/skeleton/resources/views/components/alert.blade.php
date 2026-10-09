@props(['type' => 'info', 'title', 'dismissible' => false])
<div {{ $attributes->merge(['class' => 'alert alert-' . $type]) }}>
    <strong>{{ $title }}</strong>
    {{ $slot }}
    @if ($dismissible)
        <button>{{ $componentName }}</button>
    @endif
</div>

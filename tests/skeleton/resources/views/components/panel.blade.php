<div {{ $attributes->merge(['class' => 'panel']) }}>
    <h2>{{ $heading }}</h2>
    <span>{{ $badge() }}</span>
    {{ $slot }}
</div>

@php
    /**
     * @bladestan-signature
     * @bladestan-component \App\View\Components\Notice
     */
@endphp
<div {{ $attributes }}>{{ $message }} ({{ $level() }}) {{ $slot }}</div>

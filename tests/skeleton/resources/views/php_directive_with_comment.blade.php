@php
    /** @var string $foo */
    $foo = config('foo.bar');
@endphp

{{ $foo + 10 }}

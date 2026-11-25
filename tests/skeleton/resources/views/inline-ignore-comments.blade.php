@php
/**
 * @bladestan-signature
 * @var string $title
 */
@endphp

{{ $title->nope() }}
@php // @phpstan-ignore-next-line @endphp
{{ $title->alsoNope() }}
{{ $title->sameLine() }} @php // @phpstan-ignore-line @endphp

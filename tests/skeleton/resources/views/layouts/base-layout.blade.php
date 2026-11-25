@php
/**
 * @bladestan-signature
 * @var ?string $title
 * @var string $siteName
 */
@endphp
<html lang="en">
<head><title>{{ $title ?? $siteName }}</title></head>
<body>@yield('content')</body>
</html>

{{ $foo + 10 }}

@foreach ($children as $child)
    @include('file_including_itself_inline', ['foo' => 'bar', 'children' => []])
@endforeach

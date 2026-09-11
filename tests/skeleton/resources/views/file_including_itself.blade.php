{{ $foo + 10 }}

@foreach ($children as $child)
    @include('file_including_itself', [
        'foo' => 'bar',
        'children' => [],
    ])
@endforeach

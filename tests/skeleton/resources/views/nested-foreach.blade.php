@foreach($foos as $values)
    @foreach($values as $value)
        {{ $value }}
    @endforeach
    @if($loop->first)
        What a short list!
    @endif
@endforeach

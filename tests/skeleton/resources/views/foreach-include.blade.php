@foreach($foos as $value)
	@include('bar', ['foo' => $value])
@endforeach

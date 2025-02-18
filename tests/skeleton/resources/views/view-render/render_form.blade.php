@error('php_contents')

    @foreach ($errors->get('php_contents') as $error)
        {{ $error }}
    @endforeach

@enderror

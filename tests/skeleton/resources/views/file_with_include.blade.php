<html lang="en">
    <body>
        <div class="container">
            <span>$foo is string. So it should error: </span> <pre>{{ $foo + 10 }}</pre>
        </div>

        <div class="included">
            {{-- We are overrding the $foo from before. Now it'll be integer only inside the included view. --}}
            @include('included_view', ['foo' => 10, 'bar' => $foo . 'bar'])
        </div>

        <div class="container">
            <span>$foo is string again here. So it should error: </span> <pre>{{ $foo + 20 }}</pre>
            <span>$bar was defined inside the included view. So it should error here: </span> <pre>{{ $bar }}</pre>
        </div>
    </body>
</html>

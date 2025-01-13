<x-component
    tag="asdas">
    <livewire::wired-component
        attribute="value" />
</x-component>
{{ $foo }}
-----
/** file: foo.blade.php, line: 1 */<x-component
    tag="asdas">
/** file: foo.blade.php, line: 3 */    <livewire::wired-component
        attribute="value" />
/** file: foo.blade.php, line: 5 */</x-component>
/** file: foo.blade.php, line: 6 */{{ $foo }}

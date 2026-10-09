<?php

declare(strict_types=1);

namespace ViewCallSiteUnsignedExtends;

use function view;

// The child template declares no signature of its own, but it extends a layout
// that does. Blade forwards the child's scope to the layout, so the layout's
// required variables are part of the child's contract.
view('unsigned-extends-template', [
    'title' => 'Hello World',
]);

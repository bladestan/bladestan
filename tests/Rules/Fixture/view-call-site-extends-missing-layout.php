<?php

declare(strict_types=1);

namespace ViewCallSiteExtendsMissingLayout;

use function view;

// The template extends a layout that does not exist, so its contract cannot be
// fully merged. That must surface as an error, not degrade silently.
view('extends-missing-layout', [
    'title' => 'Hello World',
]);

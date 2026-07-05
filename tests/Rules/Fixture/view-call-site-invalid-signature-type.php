<?php

declare(strict_types=1);

namespace ViewCallSiteInvalidSignatureType;

use Illuminate\Pagination\LengthAwarePaginator;

use function view;

// The template's $items type is not a valid PHPDoc type (a template
// placeholder rendered by dumpType and copied verbatim). It must be reported
// as a localized error, and the run must continue: the wrong type for $title
// below still has to be caught, proving one bad type no longer aborts the
// analysis.
view('invalid-signature-type', [
    'title' => 42,
    'items' => new LengthAwarePaginator([], 0, 10),
]);

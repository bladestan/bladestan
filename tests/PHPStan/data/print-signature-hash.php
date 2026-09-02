<?php

declare(strict_types=1);

use Bladestan\Compiler\SignatureExtractor;
use Bladestan\PHPStan\BladeSignatureCacheMetaExtension;

// Deliberately run in a process where PHPStan's bootstrap file has never
// executed: on an analyse run PHPStan restores its result cache, and with it
// asks this extension for its hash, before it executes any bootstrap file. The
// extension has to stand up an application for itself there.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

echo (new BladeSignatureCacheMetaExtension(new SignatureExtractor()))->getHash();

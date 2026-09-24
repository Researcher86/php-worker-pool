<?php

declare(strict_types=1);

/**
 * Finds and loads the right Composer autoloader for whichever of these two
 * situations this script is actually running in.
 *
 * `require __DIR__ . '/../vendor/autoload.php'` - what every bin/ script
 * here used to open with - only resolves while this repository IS the
 * Composer root: `bin/` is one level below the project root, so `../vendor`
 * is exactly right. The moment this package is installed as a dependency
 * (`vendor/tanat/php-worker-pool/`), that same relative path points at THIS
 * package's own `vendor/` - which a dependency never has one of - and the
 * script fatals before it does anything.
 *
 * The fix is to walk upward from this file's own directory until a
 * `vendor/autoload.php` actually exists. Run from the project root, that
 * finds this package's own autoloader on the very first try - unchanged
 * behavior. Run from inside `vendor/tanat/php-worker-pool/bin/`, the first
 * few levels (this package's own empty `vendor/`, then `vendor/tanat/`,
 * then `vendor/`) all miss, and the walk lands on the CONSUMING project's
 * autoloader - which is what actually has this package's classes loaded
 * into it, via Composer's own merged autoload map.
 *
 * The FIRST `vendor/autoload.php` found wins, even when a few candidates
 * exist above (a path-repository install symlinks this package into the
 * consumer's vendor/, so `__DIR__` can resolve to the source tree's own
 * `vendor/`). That is fine here - every bin/ script only uses this
 * package's own classes - so whichever autoloader actually carries them (a
 * package-root one or a consumer's) loads correctly, and the post-require
 * check below is what catches the one failure that matters: an autoloader
 * found that maps nothing of this package's.
 */
$directory = __DIR__;

while (!is_file($directory . '/vendor/autoload.php')) {
    $parent = dirname($directory);

    if ($parent === $directory) {
        fwrite(STDERR, "Could not find vendor/autoload.php above " . __DIR__ . " - run composer install.\n");
        exit(1);
    }

    $directory = $parent;
}

require $directory . '/vendor/autoload.php';

// The walk stops at the first match, so it can - in a layout with several
// vendors stacked (this package's own under a symlinked path-repository
// install, say) - land on an autoloader that maps nothing of this package.
// Loading it worked, but using it would fatal with an unrelated "class not
// found" at first use. Prefer a clear one-liner over that confusion.
if (!class_exists(\PhpWorkerPool\Protocol\Request::class, true)) {
    fwrite(
        STDERR,
        $directory . '/vendor/autoload.php does not autoload this package (' . __DIR__ . ") - using the wrong project's autoloader?\n",
    );
    exit(1);
}

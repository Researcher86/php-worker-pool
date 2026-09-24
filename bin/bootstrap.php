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

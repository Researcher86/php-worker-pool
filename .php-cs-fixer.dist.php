<?php

declare(strict_types=1);

/*
 * Shared with the sibling projects in php-systems-lab, so that code moving
 * between them does not change shape on the way: PSR-12, strict types in
 * every file, ordered imports, and native functions called by their plain
 * names.
 *
 * Two rules are switched off deliberately. native_function_invocation would
 * prefix every internal call with a backslash, and single_line_empty_body
 * would collapse an empty constructor onto one line - neither is how this
 * project was written by hand, and the formatter is here to keep the style
 * that exists rather than to impose a different one.
 */
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP84Migration' => true,
        '@PSR12' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_line_empty_body' => false,
        'strict_param' => true,
    ])
    ->setFinder($finder);

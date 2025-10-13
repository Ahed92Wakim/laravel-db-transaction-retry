<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
    ])
    ->name('*.php')
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        // 'declare_strict_types' => true,
        'no_extra_blank_lines' => true,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_superfluous_phpdoc_tags' => true,
        'phpdoc_trim' => true,
        'phpdoc_align' => ['align' => 'left'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
        'blank_line_before_statement' => ['statements' => ['return']],
        'no_whitespace_in_blank_line' => true,
        'simplified_null_return' => true,
        'void_return' => true,
    ])
    ->setFinder($finder)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect());
<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => false,
        'single_line_empty_body' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'fully_qualified_strict_types' => ['import_symbols' => true],
    ])
    ->setFinder($finder)
;

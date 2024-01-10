<?php
/*
 * This document has been generated with
 * https://mlocati.github.io/php-cs-fixer-configurator/#version:2.18.1|configurator
 * you can change this configuration by importing this file.
 */
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP80Migration:risky' => true,
        '@PHP82Migration' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'concat_space' => ['spacing' => 'one'],
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'curly_braces_position' => ['classes_opening_brace' => 'same_line', 'functions_opening_brace' => 'same_line'],
        'declare_strict_types' => false,
        'mb_str_functions' => true,
        'operator_linebreak' => true,
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'single_line_empty_body' => true,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder(PhpCsFixer\Finder::create()->exclude('vendor')->in(__DIR__))
;

<?php

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@auto:risky' => true,
        '@autoPHPMigration:risky' => true,
        'braces_position' => ['classes_opening_brace' => 'same_line', 'functions_opening_brace' => 'same_line'],
        'concat_space' => ['spacing' => 'one'],
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'declare_strict_types' => false,
        'final_internal_class' => false,
        'mb_str_functions' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'operator_linebreak' => true,
        'phpdoc_align' => ['align' => 'vertical', 'tags' => ['method', 'param', 'return', 'property', 'return', 'throws', 'type']],
        'phpdoc_to_comment' => ['allow_before_return_statement' => true, 'ignored_tags' => ['var', 'phpstan-ignore', 'phpstan-ignore-next-line', 'psalm-suppress']],
        'single_line_empty_body' => true,
        'static_lambda' => false,
        'string_implicit_backslashes' => ['double_quoted' => 'escape', 'single_quoted' => 'ignore', 'heredoc' => 'escape'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder(PhpCsFixer\Finder::create()->exclude('vendor')->in(__DIR__))
;

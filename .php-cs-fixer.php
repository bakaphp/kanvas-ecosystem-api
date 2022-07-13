<?php

/*
 * This document has been generated with
 * https://mlocati.github.io/php-cs-fixer-configurator/#version:3.0.0|configurator
 * you can change this configuration by importing this file.
 */
$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR2' => true,
        'array_indentation' => true,
        'array_syntax' => true,
        'binary_operator_spaces' => true,
        'braces' => true,
	      'combine_consecutive_unsets' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_equal_normalize' => true,
        'function_typehint_space' => true,
        'include' => true,
        'lowercase_cast' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_spaces_around_offset' => true,
        'no_unused_imports' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'object_operator_without_whitespace' => true,
        'ordered_imports' => true,
        'phpdoc_indent' => true,
        'phpdoc_return_self_reference' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_summary' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'return_type_declaration' => ['space_before'=>'one'],
        'single_blank_line_before_namespace' => true,
        'single_quote' => true,
        'ternary_operator_spaces' => true,
        'trim_array_spaces' => true,
        'unary_operator_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->exclude('vendor')
        ->in(__DIR__)
    );

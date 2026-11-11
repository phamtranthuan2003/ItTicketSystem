<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('includes/PHPMailer');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(false);

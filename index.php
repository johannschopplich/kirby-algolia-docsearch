<?php

use Kirby\Cms\App;

@include_once __DIR__ . '/vendor/autoload.php';

App::plugin('johannschopplich/algolia-docsearch', [
    'commands' => require __DIR__ . '/src/extensions/commands.php',
    'hooks' => require __DIR__ . '/src/extensions/hooks.php',
    'options' => require __DIR__ . '/src/extensions/options.php',
    'translations' => require __DIR__ . '/src/extensions/translations.php'
]);

if (!function_exists('algolia')) {
    function algolia(): \JohannSchopplich\Algolia\DocSearch
    {
        return \JohannSchopplich\Algolia\DocSearch::instance();
    }
}

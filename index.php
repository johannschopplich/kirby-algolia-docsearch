<?php

use Kirby\Cms\App;
use Kirby\Filesystem\F;

@include_once __DIR__ . '/vendor/autoload.php';

F::loadClasses([
    'JohannSchopplich\\Algolia\\DocSearch' => 'AlgoliaDocSearch.php'
], __DIR__);

App::plugin('johannschopplich/algolia-docsearch', [
    'commands' => require __DIR__ . '/extensions/commands.php',
    'hooks' => require __DIR__ . '/extensions/hooks.php',
    'translations' => require __DIR__ . '/extensions/translations.php'
]);

if (!function_exists('algolia')) {
    /**
     * Returns the Algolia DocSearch instance
     */
    function algolia(): \JohannSchopplich\Algolia\DocSearch
    {
        return \JohannSchopplich\Algolia\DocSearch::instance();
    }
}

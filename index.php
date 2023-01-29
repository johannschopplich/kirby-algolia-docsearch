<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'JohannSchopplich\\Algolia\\DocSearch' => 'AlgoliaDocSearch.php'
], __DIR__);

\Kirby\Cms\App::plugin('johannschopplich/algolia-docsearch', [
    'commands' => require __DIR__ . '/extensions/commands.php',
    'translations' => require __DIR__ . '/extensions/translations.php',
]);

function algolia(): \JohannSchopplich\Algolia\DocSearch
{
    return \JohannSchopplich\Algolia\DocSearch::instance();
}

<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'JohannSchopplich\\Algolia\\DocSearch' => 'AlgoliaDocSearch.php'
], __DIR__);

\Kirby\Cms\App::plugin('johannschopplich/algolia-docsearch', [
    'commands' => [
        'algolia-docsearch:index' => [
            'description' => 'Algolia DocSearch Index',
            'args' => [],
            'command' => function (\Kirby\CLI\CLI $cli) {
                algolia()->index();

                // Output for the command line
                if (defined('STDOUT')) {
                    $cli->success(t('johannschopplich.algolia-docsearch.index.success'));
                }

                // Output for Janitor
                if (function_exists('janitor')) {
                    janitor()->data($cli->arg('command'), [
                        'status' => 200,
                        'message' => t('johannschopplich.algolia-docsearch.index.success')
                    ]);
                }
            }
        ]
    ],
    'translations' => [
        'de' => [
            'johannschopplich.algolia-docsearch.index.start' => 'Site indexieren',
            'johannschopplich.algolia-docsearch.index.success' => 'Site erfolgreich indexiert'
        ],
        'en' => [
            'johannschopplich.algolia-docsearch.index.start' => 'Index site',
            'johannschopplich.algolia-docsearch.index.success' => 'Site indexed successfully'
        ],
        'fr' => [
            'johannschopplich.algolia-docsearch.index.start' => 'Indexer le site',
            'johannschopplich.algolia-docsearch.index.success' => 'Site indexé avec succès'
        ]
    ]
]);

function algolia(): \JohannSchopplich\Algolia\DocSearch
{
    return \JohannSchopplich\Algolia\DocSearch::instance();
}

<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\CLI\CLI;
use Kirby\Toolkit\I18n;

return [
    'algolia-docsearch:index' => [
        'description' => 'Algolia DocSearch Index',
        'args' => [],
        'command' => function (CLI $cli) {
            $docSearch = DocSearch::instance();

            // Index all pages
            $docSearch->index();

            // Output for the command line
            if (php_sapi_name() === 'cli') {
                $cli->success(I18n::translate('johannschopplich.algolia-docsearch.index.success'));
            }

            // Output for Janitor
            if (class_exists(\Bnomei\Janitor::class)) {
                $janitor = \Bnomei\Janitor::singleton();
                $janitor->data($cli->arg('command'), [
                    'status' => 200,
                    'message' => t('johannschopplich.algolia-docsearch.index.success')
                ]);
            }
        }
    ]
];

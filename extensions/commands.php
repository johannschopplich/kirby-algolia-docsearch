<?php

return [
    'algolia-docsearch:index' => [
        'description' => 'Algolia DocSearch Index',
        'args' => [],
        'command' => function (\Kirby\CLI\CLI $cli) {
            $algolia = \JohannSchopplich\Algolia\DocSearch::instance();

            // Index all pages
            $algolia->index();

            // Output for the command line
            if (php_sapi_name() === 'cli') {
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
];

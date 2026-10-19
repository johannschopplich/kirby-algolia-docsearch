<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\CLI\CLI;
use Kirby\Toolkit\I18n;

return [
    'algolia-docsearch:index' => [
        'description' => 'Algolia DocSearch Index',
        'command' => static function (CLI $cli): void {
            DocSearch::instance()->index();

            $message = I18n::translate('johannschopplich.algolia-docsearch.index.success');

            // Janitor runs commands with `--quiet`, so this prints on the command line only.
            $cli->success($message);

            if (class_exists(\Bnomei\Janitor::class)) {
                \Bnomei\Janitor::singleton()->data($cli->arg('command'), [
                    'status' => 200,
                    'message' => $message
                ]);
            }
        }
    ]
];

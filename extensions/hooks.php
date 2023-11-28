<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\Cms\Page;

return [
    'page.changeSlug:before' => function (Page $page, string $slug, string|null $languageCode = null) {
        /** @var \Kirby\Cms\App $kirby */
        $kirby = $this;

        if (
            $kirby->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $kirby->option('debug')
        ) {
            return;
        }

        $algolia = DocSearch::instance();
        $index = $algolia->getAlgoliaIndex();
        $index->deleteObject($page->uri($languageCode));
    },
    'page.delete:after' => function (bool $status, Page $page) {
        /** @var \Kirby\Cms\App $kirby */
        $kirby = $this;

        if (
            $kirby->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $kirby->option('debug')
        ) {
            return;
        }

        $algolia = DocSearch::instance();
        $index = $algolia->getAlgoliaIndex();
        $index->deleteObject($page->uri($kirby->languageCode()));
    },
    'page.*:after' => function (\Kirby\Cms\Event $event, Page|null $newPage) {
        /** @var \Kirby\Cms\App $kirby */
        $kirby = $this;

        if (
            $kirby->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $kirby->option('debug')
        ) {
            return;
        }

        // Check if we want to handle the action
        if (!in_array($event->action(), ['changeSlug', 'changeStatus', 'changeTitle', 'update'], true)) {
            return;
        }

        if (!$newPage) {
            return;
        }

        $languageCode = $kirby->languageCode();
        $algolia = DocSearch::instance();
        $index = $algolia->getAlgoliaIndex();

        if (
            $event->action() === 'delete' ||
            ($event->action() === 'changeStatus' && !$newPage->isListed())
        ) {
            $index->deleteObject($newPage->uri($languageCode));
            return;
        }

        try {
            $algolia->indexPage($newPage, $languageCode);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
];

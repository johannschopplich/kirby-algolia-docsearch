<?php

use JohannSchopplich\Algolia\DocSearch;

return [
    'page.changeSlug:before' => function (\Kirby\Cms\Page $page, string $slug, string|null $languageCode = null) {
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
    'page.*:after' => function (\Kirby\Cms\Event $event, \Kirby\Cms\Page $page) {
        /** @var \Kirby\Cms\App $kirby */
        $kirby = $this;

        if (
            $kirby->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $kirby->option('debug')
        ) {
            return;
        }

        // Check if we want to handle the action
        if (!in_array($event->action(), ['changeSlug', 'changeStatus', 'changeTitle', 'delete', 'update'], true)) {
            return;
        }

        $languageCode = $page->kirby()->languageCode();
        $algolia = DocSearch::instance();
        $index = $algolia->getAlgoliaIndex();

        if (
            $event->action() === 'delete' ||
            ($event->action() === 'changeStatus' && !$page->isListed())
        ) {
            $index->deleteObject($page->uri($languageCode));
            return;
        }

        try {
            $algolia->indexPage($page, $languageCode);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
];

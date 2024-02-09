<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Throwable;

return [
    'page.changeSlug:before' => function (Page $page, string $slug, string|null $languageCode = null) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $this->option('debug')
        ) {
            return;
        }

        $docSearch = DocSearch::instance();
        $index = $docSearch->getAlgoliaIndex();
        $index->deleteObject($page->uri($languageCode));
    },
    'page.delete:after' => function (bool $status, Page $page) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $this->option('debug')
        ) {
            return;
        }

        $docSearch = DocSearch::instance();
        $index = $docSearch->getAlgoliaIndex();
        $index->deleteObject($page->uri($this->languageCode()));
    },
    'page.*:after' => function (\Kirby\Cms\Event $event, Page|null $newPage) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $this->option('debug')
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

        $languageCode = $this->languageCode();
        $docSearch = DocSearch::instance();
        $index = $docSearch->getAlgoliaIndex();

        if (
            $event->action() === 'delete' ||
            ($event->action() === 'changeStatus' && !$newPage->isListed())
        ) {
            $index->deleteObject($newPage->uri($languageCode));
            return;
        }

        try {
            $docSearch->indexPage($newPage, $languageCode);
        } catch (Throwable $e) {
            throw new Exception('Algolia: ' . $e->getMessage());
        }
    }
];

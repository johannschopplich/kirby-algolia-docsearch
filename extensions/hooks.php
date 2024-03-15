<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\Cms\Event;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;

return [
    'page.delete:after' => function (bool $status, Page $page) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $this->option('debug')
        ) {
            return;
        }

        $languageCode = $this->languageCode();
        $docSearch = DocSearch::instance();
        $index = $docSearch->getAlgoliaIndex($languageCode);
        $index->deleteObject($page->uri($languageCode));
    },
    'page.*:after' => function (Event $event, Page|null $newPage, Page|null $oldPage) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks', false) !== true ||
            $this->option('debug')
        ) {
            return;
        }

        // Check if we want to handle the action
        if (!in_array($event->action(), ['changeSlug', 'changeStatus', 'changeTemplate', 'changeTitle', 'update'], true)) {
            return;
        }

        if (!$newPage || !$oldPage) {
            return;
        }

        $languageCode = $this->languageCode();
        $docSearch = DocSearch::instance();
        $index = $docSearch->getAlgoliaIndex($languageCode);
        $allowedTemplates = $docSearch->options['templates'] ?? [];

        if ($event->action() === 'changeSlug') {
            $index->deleteObject($oldPage->uri($languageCode));
        }

        if (
            ($event->action() === 'changeStatus' && !$newPage->isListed()) ||
            ($event->action() === 'changeTemplate' && !in_array($newPage->intendedTemplate()->name(), $allowedTemplates, true))
        ) {
            $index->deleteObject($newPage->uri($languageCode));
            return;
        }

        try {
            $docSearch->indexPage($newPage, $languageCode);
        } catch (\Exception $e) {
            throw new Exception('Algolia: ' . $e->getMessage());
        }
    }
];

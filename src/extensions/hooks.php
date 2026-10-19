<?php

use JohannSchopplich\Algolia\DocSearch;
use Kirby\Cms\Event;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;

// Kirby's after hooks don't pass the language of an edit, so the before
// hooks remember it per page.
$editedLanguageCodes = [];
$rememberEditedLanguage = function (Page $page, string|null $languageCode) use (&$editedLanguageCodes) {
    /** @var \Kirby\Cms\App $this */
    $editedLanguageCodes[$page->id()] = $this->languageCode($languageCode);
};

return [
    'page.update:before' => $rememberEditedLanguage,
    'page.changeTitle:before' => $rememberEditedLanguage,
    'page.*:after' => function (Event $event, Page|null $page, Page|null $newPage, Page|null $oldPage) use (&$editedLanguageCodes) {
        /** @var \Kirby\Cms\App $this */
        if (
            $this->option('johannschopplich.algolia-docsearch.hooks') !== true ||
            $this->option('debug')
        ) {
            return;
        }

        $action = $event->action();

        if (!in_array($action, ['delete', 'update', 'changeTitle', 'changeSlug', 'changeStatus', 'changeTemplate', 'move'], true)) {
            return;
        }

        // Kirby flushes the pages cache only after the hooks ran, so
        // rendering a page now could index its outdated cached HTML.
        $this->cache('pages')->flush();

        try {
            $docSearch = DocSearch::instance();

            match ($action) {
                'delete' => $docSearch->deletePage($page),
                // Content and title edits are saved per language.
                'update', 'changeTitle' => $docSearch->updatePage($newPage, $oldPage, $editedLanguageCodes[$newPage->id()] ?? $this->languageCode()),
                // Descendants' URLs follow the page's slug and parent, their visibility its draft state.
                'changeSlug', 'move' => $docSearch->syncPage($newPage, $oldPage, withDescendants: true),
                'changeStatus' => $docSearch->syncPage($newPage, $oldPage, withDescendants: $newPage->isDraft() !== $oldPage->isDraft()),
                'changeTemplate' => $docSearch->syncPage($newPage, $oldPage)
            };
        } catch (\Throwable $e) {
            throw new Exception(message: 'Algolia: ' . $e->getMessage(), previous: $e);
        }
    }
];

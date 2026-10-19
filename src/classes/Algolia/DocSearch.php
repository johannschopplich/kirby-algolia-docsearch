<?php

declare(strict_types = 1);

namespace JohannSchopplich\Algolia;

use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Parsley\Element;
use Kirby\Parsley\Parsley;
use Kirby\Parsley\Schema\Plain as PlainSchema;
use Kirby\Toolkit\Dom;
use Kirby\Toolkit\I18n;

class DocSearch
{
    protected static self|null $instance = null;

    protected Algolia $algolia;

    /**
     * Algolia index configuration for DocSearch, after
     * https://docsearch.algolia.com/docs/templates.
     */
    protected array $indexSettings = [
        'attributesForFaceting' => ['type', 'lang'],
        'attributesToRetrieve' => [
            'hierarchy',
            'content',
            'url',
            'type',
        ],
        'attributesToHighlight' => ['hierarchy', 'content'],
        'attributesToSnippet' => ['content:10'],
        'camelCaseAttributes' => ['hierarchy', 'content'],
        'searchableAttributes' => [
            'unordered(hierarchy.lvl0)',
            'unordered(hierarchy.lvl1)',
            'unordered(hierarchy.lvl2)',
            'unordered(hierarchy.lvl3)',
            'unordered(hierarchy.lvl4)',
            'unordered(hierarchy.lvl5)',
            'unordered(hierarchy.lvl6)',
            'content',
        ],
        'distinct' => true,
        'attributeForDistinct' => 'url',
        'customRanking' => [
            'desc(weight.pageRank)',
            'desc(weight.level)',
            'asc(weight.position)',
        ],
        'ranking' => [
            'words',
            'filters',
            'typo',
            'attribute',
            'proximity',
            'exact',
            'custom',
        ],
        'minWordSizefor1Typo' => 3,
        'minWordSizefor2Typos' => 7,
        'allowTyposOnNumericTokens' => false,
        'minProximity' => 1,
        'ignorePlurals' => true,
        'advancedSyntax' => true,
        'attributeCriteriaComputedByMinProximity' => true,
        'removeWordsIfNoResults' => 'allOptional',
        'separatorsToIndex' => '_',
    ];

    public array $options = [];

    public function __construct()
    {
        $this->options = App::instance()->option('johannschopplich.algolia-docsearch');

        if (($this->options['appId'] ?? '') === '' || ($this->options['apiKey'] ?? '') === '') {
            throw new Exception('Missing Algolia API credentials');
        }

        if (($this->options['index'] ?? '') === '') {
            throw new Exception('Missing Algolia index name');
        }

        $this->algolia = Algolia::create(
            $this->options['appId'],
            $this->options['apiKey']
        );
    }

    public static function instance(): self
    {
        return static::$instance ??= new static();
    }

    public function getAlgoliaIndex(string|null $languageCode = null): \Algolia\AlgoliaSearch\SearchIndex
    {
        return $this->algolia->initIndex($this->indexName($languageCode));
    }

    /**
     * Indexes the whole site and replaces the current Algolia index
     * or multiple Algolia indices if Kirby languages are enabled.
     */
    public function index(): void
    {
        $this->forEachLanguage($this->buildIndex(...));
    }

    /**
     * Saves a single page to the Algolia index of the given language,
     * or removes it from there if it is no longer indexable.
     */
    public function indexPage(Page $page, string|null $languageCode = null): void
    {
        $this->inLanguage($languageCode, function () use ($page, $languageCode) {
            $index = $this->getAlgoliaIndex($languageCode);

            if ($this->isIndexable($page)) {
                $index->saveObject($this->format($page, $languageCode));
            } elseif ($this->hasIndexedTemplate($page)) {
                $index->deleteObject($page->uri($languageCode));
            }
        });
    }

    /**
     * Updates a page's index after a content or title edit in one language.
     * Translations show the default language's content for fields they
     * don't translate, so an edit in the default language reaches every
     * language's index.
     */
    public function updatePage(Page $page, Page $oldPage, string|null $languageCode): void
    {
        if (App::instance()->language($languageCode)?->isDefault()) {
            $this->syncPage($page, $oldPage);
            return;
        }

        $this->indexPage($page, $languageCode);
    }

    /**
     * Updates a page in the Algolia index of every language after a
     * change that reaches all of them (default-language content, slug,
     * status, template or parent) and removes its records under the old
     * URL. With `$withDescendants`, the descendants follow, for changes
     * that affect their URLs or draft state too.
     */
    public function syncPage(Page $page, Page $oldPage, bool $withDescendants = false): void
    {
        $pages = $withDescendants ? [$page, ...$page->index()->values()] : [$page];

        $this->forEachLanguage(function (string|null $languageCode) use ($pages, $page, $oldPage) {
            $uri = $page->uri($languageCode);

            // A moved page can't read its translated slug from the old content
            // file anymore, so its old URL may also be the old parent's URL plus
            // the unchanged slug; deleting a record that doesn't exist is a no-op.
            $oldUris = array_unique([
                $oldPage->uri($languageCode),
                ltrim($oldPage->parent()?->uri($languageCode) . '/' . $page->slug($languageCode), '/')
            ]);

            $objects = [];
            $oldObjectIds = [];

            foreach ($pages as $item) {
                if ($this->isIndexable($item)) {
                    $objects[] = $this->format($item, $languageCode);
                }

                // Only pages of an indexed template can have a record, and for
                // the page itself that is its template before the change.
                if ($this->hasIndexedTemplate($item === $page ? $oldPage : $item)) {
                    $path = substr($item->uri($languageCode), strlen($uri));

                    foreach ($oldUris as $oldUri) {
                        $oldObjectIds[] = $oldUri . $path;
                    }
                }
            }

            $index = $this->getAlgoliaIndex($languageCode);
            $staleObjectIds = array_diff($oldObjectIds, array_column($objects, 'objectID'));

            if ($staleObjectIds !== []) {
                $index->deleteObjects(array_values($staleObjectIds));
            }

            if ($objects !== []) {
                $index->saveObjects($objects);
            }
        });
    }

    /**
     * Removes a page from the Algolia index of every language.
     */
    public function deletePage(Page $page): void
    {
        if (!$this->hasIndexedTemplate($page)) {
            return;
        }

        $this->forEachLanguage(
            fn (string|null $languageCode) => $this->getAlgoliaIndex($languageCode)->deleteObject($page->uri($languageCode))
        );
    }

    /**
     * Replaces one language's index with the site's indexable pages and
     * applies the DocSearch index settings. Algolia swaps the new index in
     * atomically, so searches never hit a half-built one.
     *
     * @see https://www.algolia.com/doc/api-reference/api-methods/replace-all-objects/
     */
    public function buildIndex(string|null $languageCode = null): void
    {
        $kirby = App::instance();

        // Get all pages that should be indexed
        $pages = $kirby->site()->index()->filter([$this, 'isIndexable']);

        // Convert pages to Algolia data arrays
        $objects = $pages->map(fn (Page $page) => $this->format($page, $languageCode));

        // Get Algolia index
        $index = $this->getAlgoliaIndex($languageCode);

        // Replace all objects in the index
        $index->replaceAllObjects($objects);

        // Merge custom attributes with the default index settings
        $settings = $this->indexSettings;
        $settings['attributesToRetrieve'] = [
            ...$settings['attributesToRetrieve'],
            ...array_keys($this->options['attributes'])
        ];
        $settings['searchableAttributes'] = [
            ...$settings['searchableAttributes'],
            ...$this->options['searchableAttributes']
        ];

        // Each index setting from the config replaces the plugin's value, including
        // what the `attributes` and `searchableAttributes` options added.
        $settings = array_replace($settings, $this->options['indexSettings']);

        // Set index settings for Algolia DocSearch
        $index->setSettings($settings);
    }

    public function isIndexable(Page $page): bool
    {
        // Drafts and their children have no public URL.
        if ($page->isDraft() || $page->parents()->findBy('status', 'draft') !== null) {
            return false;
        }

        $excludePages = $this->options['exclude']['pages'];

        if (preg_match('!^(?:' . implode('|', $excludePages) . ')$!i', $page->id())) {
            return false;
        }

        if (!$this->hasIndexedTemplate($page)) {
            return false;
        }

        return $this->options['filter']($page) === true;
    }

    /**
     * Builds the Algolia data array from a Kirby page, with Kirby's
     * current language set to the given one.
     */
    public function format(Page $page, string|null $languageCode): array
    {
        return $this->inLanguage($languageCode, fn () => $this->buildRecord($page, $languageCode));
    }

    protected function hasIndexedTemplate(Page $page): bool
    {
        return in_array($page->intendedTemplate()->name(), $this->options['templates'], true);
    }

    protected function indexName(string|null $languageCode): string
    {
        return $this->options['index'] . ($languageCode !== null ? '-' . $languageCode : '');
    }

    protected function buildRecord(Page $page, string|null $languageCode): array
    {
        $label = $this->options['label'];
        $pageTemplate = $page->intendedTemplate()->name();

        // Determine the correct label for lvl0
        $lvl0Label = $label['templates'][$pageTemplate] ?? $label['default'] ?? throw new Exception('Missing default label');
        if (is_array($lvl0Label)) {
            $lvl0Label = $lvl0Label[$languageCode] ?? throw new Exception('Missing label for language code: ' . $languageCode);
        }

        // Build resulting data array
        $data = [
            'objectID' => $page->uri($languageCode),
            'url' => $page->url($languageCode),
            'type' => 'lvl1',
            'hierarchy' => [
                'lvl0' => $lvl0Label,
                'lvl1' => $page->content($languageCode)->get('title')->value()
            ]
        ];

        // Add content
        $content = $this->options['content'];
        if (is_string($content)) {
            $data['content'] = $this->pageToText($page, $content);
        } else {
            $renderFn = is_array($content)
                ? $content[$pageTemplate] ?? $content['default'] ?? null
                : $content;

            if (!is_callable($renderFn)) {
                throw new Exception('Expected "content" to be a string, callable or an array of callables, got: ' . gettype($renderFn));
            }

            $data['content'] = $renderFn($page, $languageCode);
        }

        // Add custom attributes
        foreach ($this->options['attributes'] as $attribute => $fn) {
            if (!is_callable($fn)) {
                throw new Exception('Expected "attributes" value to be a callable, got: ' . gettype($fn));
            }

            $data[$attribute] = $fn($page, $languageCode);
        }

        return $data;
    }

    /**
     * Extracts the text content from a rendered page.
     */
    protected function pageToText(Page $page, string $tag): string
    {
        // `render()` takes no language, so `format()` sets Kirby's current one.
        $html = $page->render();

        // Separate block-level elements, so the text of `<li>One</li><li>Two</li>`
        // doesn't run together as "OneTwo", while inline markup such as
        // `foo<sup>1</sup>` stays one word.
        $html = preg_replace(
            '#</(?:address|article|aside|blockquote|caption|dd|details|dialog|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|legend|li|main|menu|nav|ol|p|pre|search|section|summary|table|tbody|td|tfoot|th|thead|tr|ul)>|<(?:br|hr)\b[^>]*>#i',
            '$0 ',
            $html
        );

        // Extract the HTML from inside the given tag
        $dom = new Dom($html);
        $node = $dom->query("//{$tag}")[0];

        if ($node === null) {
            return '';
        }

        $element = new Element($node);

        // Initialize Parsley
        $schema = new PlainSchema();
        $parsley = new Parsley($element->innerHtml(), $schema);
        $blocks = $parsley->blocks();

        // Reduce blocks to text
        $text = implode(
            ' ',
            array_map(fn (array $block) => $block['content']['text'], $blocks)
        );

        // Collapse the template's indentation and line breaks, which
        // count toward Algolia's record size limit.
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Calls the callback in each language with its code, or once with
     * `null` on single-language sites.
     */
    protected function forEachLanguage(\Closure $callback): void
    {
        $kirby = App::instance();

        if (!$kirby->multilang()) {
            $callback(null);
            return;
        }

        foreach ($kirby->languages()->codes() as $languageCode) {
            $this->inLanguage($languageCode, fn () => $callback($languageCode));
        }
    }

    /**
     * Calls the callback with Kirby's current language and translation set
     * to the given language, so rendered pages and `t()` strings match it,
     * and restores both afterwards.
     */
    protected function inLanguage(string|null $languageCode, \Closure $callback): mixed
    {
        $kirby = App::instance();

        if ($languageCode === null || !$kirby->multilang()) {
            return $callback();
        }

        $currentLanguageCode = $kirby->languageCode();
        $currentLocale = I18n::$locale;

        try {
            $kirby->setCurrentLanguage($languageCode);
            $kirby->setCurrentTranslation($languageCode);

            return $callback();
        } finally {
            $kirby->setCurrentLanguage($currentLanguageCode);
            I18n::$locale = $currentLocale;
        }
    }
}

<?php

namespace JohannSchopplich\Algolia;

use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Kirby\Cms\Page;
use Kirby\Parsley\Element;
use Kirby\Parsley\Parsley;
use Kirby\Parsley\Schema\Plain as PlainSchema;
use Kirby\Toolkit\Dom;

class DocSearch
{
    /**
     * Singleton class instance
     */
    public static \JohannSchopplich\Algolia\DocSearch $instance;

    /**
     * Algolia client instance
     */
    protected Algolia $algolia;

    /**
     * Algolia index configuration for DocSearch
     * Reference: https://docsearch.algolia.com/docs/templates
     */
    protected array $indexSettings = [
        'attributesForFaceting' => ['type', 'lang'],
        'attributesToRetrieve' => [
            'hierarchy',
            'content',
            'anchor',
            'url',
            'url_without_anchor',
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
        'highlightPreTag' => '<span class="algolia-docsearch-suggestion--highlight">',
        'highlightPostTag' => '</span>',
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

    /**
     * Config settings
     */
    protected array $options = [];

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->options = option('johannschopplich.algolia-docsearch', []);

        if (!isset($this->options['appId'], $this->options['apiKey'])) {
            throw new \Exception('Please set your Algolia API credentials in the Kirby configuration.');
        }

        $this->algolia = Algolia::create(
            $this->options['appId'],
            $this->options['apiKey']
        );
    }

    /**
     * Returns a singleton instance of the Algolia DocSearch class
     */
    public static function instance(): self
    {
        return static::$instance ??= new static();
    }

    /**
     * Indexes the whole site and replaces the current Algolia index
     * or multiple Algolia indices if Kirby languages are enabled
     */
    public function index(): void
    {
        if (kirby()->multilang()) {
            foreach (kirby()->languages()->codes() as $languageCode) {
                kirby()->setCurrentLanguage($languageCode);
                $this->buildIndex($languageCode);
            }
        } else {
            $this->buildIndex();
        }
    }

    /**
     * Indexes the whole site, creates or updates the Algolia index and
     * sets the required settings for Algolia DocSearch
     *
     * If a language code is provided, the index will be suffixed with it
     *
     * Uses atomical re-indexing:
     * https://www.algolia.com/doc/api-reference/api-methods/replace-all-objects/
     */
    public function buildIndex(string|null $languageCode = null): void
    {
        // Get all pages that should be indexed
        $pages = site()->index()->filter([$this, 'isIndexable']);

        // Convert pages to Algolia data arrays
        $objects = $pages->map(fn (Page $page) => $this->format($page, $languageCode));

        // Get Algolia index
        $indexName = $this->options['index'] . (!empty($languageCode) ? "-{$languageCode}" : '');
        $index = $this->algolia->initIndex($indexName);

        // Replace all objects in the index
        $index->replaceAllObjects($objects);

        // Set index settings for Algolia DocSearch
        $index->setSettings($this->indexSettings);
    }

    /**
     * Checks if a specific page should be included in the Algolia index
     */
    public function isIndexable(Page $page): bool
    {
        $templates = $this->options['templates'] ?? [];
        $pageTemplate = $page->intendedTemplate()->name();
        $excludePages = $this->options['exclude']['pages'] ?? [];

        if (preg_match('!^(?:' . implode('|', $excludePages) . ')$!i', $page->id())) {
            return false;
        }

        return in_array($pageTemplate, $templates, true);
    }

    /**
     * Converts a page into a data array for Algolia
     */
    public function format(Page $page, string|null $languageCode): array
    {
        $label = $this->options['label'] ?? [];
        $pageTemplate = $page->intendedTemplate()->name();

        // Build resulting data array
        $data = [
            'objectID' => $page->uri(),
            'url' => $page->url(),
            'type' => 'lvl1',
            'hierarchy' => [
                'lvl0' => $label['templates'][$pageTemplate][$languageCode]
                    ?? $label['templates'][$pageTemplate]
                    ?? $label['default'][$languageCode]
                    ?? $label['default'],
                'lvl1' => $page->content($languageCode)->get('title')->value()
            ]
        ];

        // Add title
        $data['title'] = $page->content($languageCode)->get('title')->value();

        // Add content
        $content = $this->options['content'] ?? null;
        $result = is_array($content) ? ($content[$pageTemplate] ?? null) : $content;
        $data['content'] = is_callable($result) ? $result($page) : $this->pageToText($page, $result ?? 'body');

        return $data;
    }

    /**
     * Extracts the text content from a rendered page
     */
    protected function pageToText(Page $page, string $tag = 'body'): string
    {
        $html = $page->render();

        // Extract the HTML from inside the given tag
        $dom = new Dom($html);
        $node = $dom->query("//{$tag}")[0];
        $element = new Element($node);

        // Initialize Parsley
        $schema = new PlainSchema();
        $parsley = new Parsley($element->innerHtml(), $schema);
        $blocks = $parsley->blocks();

        // Reduce blocks to text
        return implode(
            ' ',
            array_map(fn (array $block) => $block['content']['text'], $blocks)
        );
    }
}

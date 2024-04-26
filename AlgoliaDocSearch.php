<?php

namespace JohannSchopplich\Algolia;

use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Parsley\Element;
use Kirby\Parsley\Parsley;
use Kirby\Parsley\Schema\Plain as PlainSchema;
use Kirby\Toolkit\Dom;

class DocSearch
{
    /**
     * Singleton class instance
     */
    public static DocSearch $instance;

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
    public array $options = [];

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->options = App::instance()->option('johannschopplich.algolia-docsearch', []);

        if (!isset($this->options['appId'], $this->options['apiKey'])) {
            throw new Exception('Missing Algolia API credentials');
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
     * Returns the Algolia index for the given language code
     */
    public function getAlgoliaIndex(string|null $languageCode = null): \Algolia\AlgoliaSearch\SearchIndex
    {
        $indexName = $this->options['index'] . (!empty($languageCode) ? '-' . $languageCode : '');
        return $this->algolia->initIndex($indexName);
    }

    /**
     * Indexes the whole site and replaces the current Algolia index
     * or multiple Algolia indices if Kirby languages are enabled
     */
    public function index(): void
    {
        $kirby = App::instance();

        if ($kirby->multilang()) {
            foreach ($kirby->languages()->codes() as $languageCode) {
                $kirby->setCurrentLanguage($languageCode);
                $this->buildIndex($languageCode);
            }
        } else {
            $this->buildIndex();
        }
    }

    /**
     * Indexes a single page and saves it to the current Algolia index
     */
    public function indexPage(Page $page, string|null $languageCode = null): void
    {
        if (!$this->isIndexable($page)) {
            return;
        }

        // Convert page to Algolia data array
        $object = $this->format($page, $languageCode);

        // Get Algolia index
        $index = $this->getAlgoliaIndex($languageCode);

        // Save object to index
        $index->saveObject($object);
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

        if (isset($this->options['attributes'])) {
            $settings['attributesToRetrieve'] = array_merge(
                $settings['attributesToRetrieve'],
                array_keys($this->options['attributes'])
            );
        }

        // Set index settings for Algolia DocSearch
        $index->setSettings($settings);
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

        if (!$page->isListed()) {
            return false;
        }

        return in_array($pageTemplate, $templates, true);
    }

    /**
     * Builds the Algolia data array from a Kirby page
     */
    public function format(Page $page, string|null $languageCode): array
    {
        $label = $this->options['label'] ?? [];
        $pageTemplate = $page->intendedTemplate()->name();

        // Determine the correct label for lvl0
        $lvl0Label = $label['default'];
        if (isset($label['templates'][$pageTemplate])) {
            if (is_array($label['templates'][$pageTemplate])) {
                if (!isset($label['templates'][$pageTemplate][$languageCode])) {
                    throw new Exception('Missing label for language code: ' . $languageCode);
                }
                $lvl0Label = $label['templates'][$pageTemplate][$languageCode];
            } else {
                $lvl0Label = $label['templates'][$pageTemplate];
            }
        } elseif (is_array($label['default'])) {
            if (!isset($label['default'][$languageCode])) {
                throw new Exception('Missing default label for language code: ' . $languageCode);
            }
            $lvl0Label = $label['default'][$languageCode];
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

        // Add title
        $data['title'] = $page->content($languageCode)->get('title')->value();

        // Add content
        $content = $this->options['content'] ?? 'body';
        if (is_string($content)) {
            $data['content'] = $this->pageToText($page, $content, $languageCode);
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
        $attributes = $this->options['attributes'] ?? [];

        foreach ($attributes as $attribute => $fn) {
            if (!is_callable($fn)) {
                throw new Exception('Expected "attributes" value to be a callable, got: ' . gettype($fn));
            }

            $data[$attribute] = $fn($page, $languageCode);
        }

        return $data;
    }

    /**
     * Extracts the text content from a rendered page
     */
    protected function pageToText(Page $page, string $tag, string|null $languageCode): string
    {
        // TODO: Fix multilang issue
        // Render page for the given language
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

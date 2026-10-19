<?php

declare(strict_types = 1);

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DocSearchTest extends TestCase
{
    private const ROOT = __DIR__ . '/tmp';

    /**
     * Listed `docs` with a listed child, plus one page for each reason
     * to leave a page out: unlisted, another template, excluded by
     * `exclude.pages`, a draft, and a listed child of a draft.
     */
    private const SITE = [
        'children' => [
            [
                'slug' => 'docs',
                'num' => 1,
                'template' => 'default',
                'content' => ['title' => 'Docs', 'text' => 'Start here'],
                'children' => [
                    ['slug' => 'intro', 'num' => 1, 'template' => 'default', 'content' => ['title' => 'Intro']],
                ],
            ],
            ['slug' => 'imprint', 'template' => 'default', 'content' => ['title' => 'Imprint']],
            ['slug' => 'blog', 'num' => 2, 'template' => 'blog', 'content' => ['title' => 'Blog']],
            ['slug' => 'private', 'num' => 3, 'template' => 'default', 'content' => ['title' => 'Private']],
        ],
        'drafts' => [
            [
                'slug' => 'wip',
                'template' => 'default',
                'content' => ['title' => 'WIP'],
                'children' => [
                    ['slug' => 'chapter', 'num' => 1, 'template' => 'default', 'content' => ['title' => 'Chapter']],
                ],
            ],
        ],
    ];

    protected function tearDown(): void
    {
        FakeDocSearch::uninstall();
        App::destroy();
        Dir::remove(self::ROOT);
    }

    private function app(array $options = [], array $props = []): App
    {
        return new App([
            'roots' => ['index' => self::ROOT],
            'urls' => ['index' => 'https://example.com'],
            'site' => self::SITE,
            ...$props,
            'options' => [
                'johannschopplich.algolia-docsearch' => [
                    'appId' => 'APP_ID',
                    'apiKey' => 'API_KEY',
                    'index' => 'example',
                    'templates' => ['default'],
                    'exclude' => ['pages' => ['PRIVATE']],
                    'label' => ['default' => 'Page'],
                    'content' => fn (Page $page, string|null $languageCode) => $page->content($languageCode)->get('text')->value(),
                    ...$options
                ],
                ...$props['options'] ?? []
            ]
        ]);
    }

    /**
     * A multi-language site on disk, so slug and template changes and
     * moves work, with the hooks enabled.
     */
    private function multilangApp(array $options = []): App
    {
        Dir::make(self::ROOT . '/content');

        $app = $this->app(['hooks' => true, ...$options], [
            'site' => [],
            'languages' => [
                ['code' => 'en', 'default' => true, 'url' => '/'],
                ['code' => 'de', 'url' => '/de'],
            ],
            'blueprints' => [
                'pages/default' => ['options' => ['changeTemplate' => ['note']], 'sections' => ['pages' => ['type' => 'pages']]],
                'pages/note' => ['title' => 'Note'],
            ],
        ]);
        $app->impersonate('kirby');

        return $app;
    }

    private function publish(Page|App $parent, string $slug, array $content = []): Page
    {
        $parent = $parent instanceof App ? $parent->site() : $parent;

        return $parent
            ->createChild(['slug' => $slug, 'template' => 'default', 'content' => ['title' => ucfirst($slug), ...$content]])
            ->changeStatus('listed');
    }

    #[Test]
    public function indexes_listed_pages_of_the_configured_templates(): void
    {
        $this->app();
        $docSearch = FakeDocSearch::install();

        $docSearch->index();

        $this->assertSame(['docs', 'docs/intro'], $docSearch->objectIds('example'));
    }

    #[Test]
    public function indexes_the_pages_the_filter_accepts_but_never_drafts(): void
    {
        $app = $this->app(['filter' => fn (Page $page): bool => true]);
        $docSearch = FakeDocSearch::install();

        $docSearch->index();

        $this->assertSame(['docs', 'docs/intro', 'imprint'], $docSearch->objectIds('example'));
        $this->assertFalse($docSearch->isIndexable($app->site()->draft('wip')->find('chapter')));
    }

    #[Test]
    public function formats_a_page_as_a_docsearch_record(): void
    {
        $app = $this->app([
            'attributes' => [
                'tags' => fn (Page $page) => ['kirby', 'search'],
            ],
        ]);
        $docSearch = FakeDocSearch::install();

        $this->assertSame([
            'objectID' => 'docs',
            'url' => 'https://example.com/docs',
            'type' => 'lvl1',
            'hierarchy' => [
                'lvl0' => 'Page',
                'lvl1' => 'Docs',
            ],
            'content' => 'Start here',
            'tags' => ['kirby', 'search'],
        ], $docSearch->format($app->page('docs'), null));
    }

    /** @return array<string, array{0: array, 1: string, 2: string}> */
    public static function labelCases(): array
    {
        return [
            'default label per language' => [['default' => ['en' => 'Page', 'de' => 'Seite']], 'docs', 'Seite'],
            'template label' => [['default' => 'Page', 'templates' => ['guide' => 'Guide']], 'guides', 'Guide'],
            'template label per language' => [['default' => 'Page', 'templates' => ['guide' => ['en' => 'Guide', 'de' => 'Anleitung']]], 'guides', 'Anleitung'],
            'template label without default' => [['templates' => ['guide' => 'Guide']], 'guides', 'Guide'],
        ];
    }

    #[Test]
    #[DataProvider('labelCases')]
    public function resolves_the_label_by_template_and_language(array $label, string $pageId, string $expected): void
    {
        $app = $this->app(['label' => $label], [
            'site' => [
                'children' => [
                    ['slug' => 'docs', 'template' => 'default'],
                    ['slug' => 'guides', 'template' => 'guide'],
                ],
            ],
            'languages' => [
                ['code' => 'en', 'default' => true],
                ['code' => 'de'],
            ],
        ]);
        $docSearch = FakeDocSearch::install();

        $this->assertSame($expected, $docSearch->format($app->page($pageId), 'de')['hierarchy']['lvl0']);
    }

    #[Test]
    public function throws_for_a_missing_label(): void
    {
        $app = $this->app(['label' => ['default' => ['en' => 'Page']]], [
            'languages' => [
                ['code' => 'en', 'default' => true],
                ['code' => 'de'],
            ],
        ]);
        $docSearch = FakeDocSearch::install();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing label for language code: de');

        $docSearch->format($app->page('docs'), 'de');
    }

    #[Test]
    public function throws_without_a_default_label(): void
    {
        $app = $this->app(['label' => []]);
        $docSearch = FakeDocSearch::install();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing default label');

        $docSearch->format($app->page('docs'), null);
    }

    #[Test]
    public function throws_without_api_credentials(): void
    {
        $this->app(['appId' => '']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing Algolia API credentials');

        FakeDocSearch::install();
    }

    #[Test]
    public function throws_without_an_index_name(): void
    {
        $this->app(['index' => null]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing Algolia index name');

        FakeDocSearch::install();
    }

    #[Test]
    public function replaces_default_index_settings_and_extends_the_attribute_lists(): void
    {
        $this->app([
            'attributes' => ['tags' => fn (Page $page) => []],
            'searchableAttributes' => ['tags'],
            'indexSettings' => ['ranking' => ['typo', 'words']],
        ]);
        $docSearch = FakeDocSearch::install();

        $docSearch->index();
        $settings = $docSearch->getAlgoliaIndex()->settings;

        $this->assertSame(['typo', 'words'], $settings['ranking']);
        $this->assertSame('tags', end($settings['attributesToRetrieve']));
        $this->assertSame('tags', end($settings['searchableAttributes']));
        $this->assertSame(['desc(weight.pageRank)', 'desc(weight.level)', 'asc(weight.position)'], $settings['customRanking']);
    }

    #[Test]
    public function extracts_the_content_from_the_rendered_tag_or_leaves_it_empty(): void
    {
        F::write(self::ROOT . '/site/templates/default.php', "<header>Menu</header><main>\n    <p>Hello</p>\n    <p>World</p><p>Again</p>\n    <p>Note<sup><a href=\"#fn1\">1</a></sup></p>\n</main>");
        F::write(self::ROOT . '/site/templates/plain.php', '<p>No main element</p>');

        $app = $this->app(['templates' => ['default', 'plain'], 'content' => 'main'], [
            'site' => [
                'children' => [
                    ['slug' => 'docs', 'num' => 1, 'template' => 'default'],
                    ['slug' => 'plain', 'num' => 2, 'template' => 'plain'],
                ],
            ],
        ]);
        $docSearch = FakeDocSearch::install();

        $this->assertSame('Hello World Again Note1', $docSearch->format($app->page('docs'), null)['content']);
        $this->assertSame('', $docSearch->format($app->page('plain'), null)['content']);
    }

    #[Test]
    public function extracts_the_content_rendered_in_each_language(): void
    {
        F::write(self::ROOT . '/site/templates/default.php', '<main><?= $page->text() ?></main>');
        $app = $this->multilangApp(['content' => 'main']);
        $docSearch = FakeDocSearch::install();

        $app->site()
            ->createChild(['slug' => 'docs', 'template' => 'default', 'content' => ['title' => 'Docs', 'text' => 'Hello']])
            ->update(['text' => 'Hallo'], 'de')
            ->changeStatus('listed');

        $this->assertSame('Hello', $docSearch->getAlgoliaIndex('en')->records['docs']['content']);
        $this->assertSame('Hallo', $docSearch->getAlgoliaIndex('de')->records['docs']['content']);
        $this->assertSame('Hallo', $docSearch->format($app->page('docs'), 'de')['content']);
    }

    #[Test]
    public function publishing_a_page_indexes_it_with_its_descendants_in_every_language(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();

        $docs = $app->site()->createChild(['slug' => 'docs', 'template' => 'default', 'content' => ['title' => 'Docs']]);
        $this->publish($docs, 'intro');
        $docs->changeStatus('listed');

        $this->assertSame(['docs', 'docs/intro'], $docSearch->objectIds('example-en'));
        $this->assertSame(['docs', 'docs/intro'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function changing_the_slug_moves_the_records_of_the_page_and_its_descendants(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($this->publish($app, 'docs'), 'intro');

        $app->page('docs')->changeSlug('guide');

        $this->assertSame(['guide', 'guide/intro'], $docSearch->objectIds('example-en'));
        $this->assertSame(['guide', 'guide/intro'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function changing_a_translated_slug_moves_the_records_of_that_language(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($this->publish($app, 'docs'), 'intro');

        $app->page('docs')->changeSlug('doku', 'de');

        $this->assertSame(['docs', 'docs/intro'], $docSearch->objectIds('example-en'));
        $this->assertSame(['doku', 'doku/intro'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function moving_a_page_moves_its_records_under_the_translated_slug(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'manual');
        $this->publish($this->publish($app, 'docs'), 'intro');
        $app->page('docs')->changeSlug('doku', 'de');

        $app->page('docs')->move($app->page('manual'));

        $this->assertSame(['manual', 'manual/docs', 'manual/docs/intro'], $docSearch->objectIds('example-en'));
        $this->assertSame(['manual', 'manual/doku', 'manual/doku/intro'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function unpublishing_a_page_removes_it_with_its_descendants_from_every_language(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'about');
        $this->publish($this->publish($app, 'docs'), 'intro');
        $app->page('docs')->changeSlug('doku', 'de');

        $app->page('docs')->changeStatus('draft');

        $this->assertSame(['about'], $docSearch->objectIds('example-en'));
        $this->assertSame(['about'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function changing_to_a_template_outside_the_configured_ones_removes_the_page_from_every_language(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'docs');

        $app->page('docs')->changeTemplate('note');

        $this->assertSame([], $docSearch->objectIds('example-en'));
        $this->assertSame([], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function deleting_a_page_removes_it_with_its_descendants_from_every_language(): void
    {
        $app = $this->multilangApp();
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'about');
        $this->publish($this->publish($app, 'docs'), 'intro');

        $app->page('docs')->delete(force: true);

        $this->assertSame(['about'], $docSearch->objectIds('example-en'));
        $this->assertSame(['about'], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function updating_translated_content_reindexes_that_language_only(): void
    {
        F::write(self::ROOT . '/site/templates/default.php', '<main><?= $page->text() ?></main>');
        $app = $this->multilangApp(['content' => 'main']);
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'docs', ['text' => 'Before']);
        // Reindexing English would overwrite the stale record.
        $docSearch->getAlgoliaIndex('en')->records['docs']['content'] = 'Stale';

        $app->page('docs')->update(['text' => 'Nachher'], 'de');

        $this->assertSame('Stale', $docSearch->getAlgoliaIndex('en')->records['docs']['content']);
        $this->assertSame('Nachher', $docSearch->getAlgoliaIndex('de')->records['docs']['content']);
    }

    #[Test]
    public function updating_translated_content_the_filter_rejects_removes_the_page_from_that_language(): void
    {
        $app = $this->multilangApp(['filter' => fn (Page $page): bool => $page->content()->get('noindex')->toBool() === false]);
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'docs');

        $app->page('docs')->update(['noindex' => 'true'], 'de');

        $this->assertSame(['docs'], $docSearch->objectIds('example-en'));
        $this->assertSame([], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function updating_default_language_content_the_filter_rejects_removes_the_page_from_every_language(): void
    {
        $app = $this->multilangApp(['filter' => fn (Page $page): bool => $page->content()->get('noindex')->toBool() === false]);
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'docs');

        // The `de` translation has no `noindex` of its own and falls back to the default language's.
        $app->page('docs')->update(['noindex' => 'true']);

        $this->assertSame([], $docSearch->objectIds('example-en'));
        $this->assertSame([], $docSearch->objectIds('example-de'));
    }

    #[Test]
    public function saving_content_on_a_single_language_site_reindexes_the_unsuffixed_index(): void
    {
        Dir::make(self::ROOT . '/content');
        $app = $this->app(['hooks' => true], ['site' => []]);
        $app->impersonate('kirby');
        $docSearch = FakeDocSearch::install();
        $this->publish($app, 'docs', ['text' => 'Before']);

        // The Panel's save publishes the changes version.
        $changes = $app->page('docs')->version('changes');
        $changes->save(['text' => 'After']);
        $changes->publish();

        $this->assertSame(['example'], array_keys($docSearch->indices));
        $this->assertSame('After', $docSearch->getAlgoliaIndex()->records['docs']['content']);
    }

    #[Test]
    public function hooks_stay_off_in_debug_mode(): void
    {
        Dir::make(self::ROOT . '/content');
        $app = $this->app(['hooks' => true], ['site' => [], 'options' => ['debug' => true]]);
        $app->impersonate('kirby');
        $docSearch = FakeDocSearch::install();

        $this->publish($app, 'docs');

        $this->assertSame([], $docSearch->indices);
    }
}

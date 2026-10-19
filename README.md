<div align="center">
  <img src="./.github/favicon.svg" alt="Kirby Algolia DocSearch logo" width="120">

# Kirby Algolia DocSearch

Kirby Algolia DocSearch is a plugin for [Kirby CMS](https://getkirby.com) that indexes your pages into Algolia for the [DocSearch](https://docsearch.algolia.com) search modal. It builds the records itself, so you don't need Algolia's paid crawler.

[Features](#features) •
[DocSearch Setup](#docsearch-setup) •
[Options](#options) •
[Limits](#limits)

</div>

## When to Use

| I want to…                                 | Use                                          |
| ------------------------------------------ | -------------------------------------------- |
| Index the whole site                       | `kirby algolia-docsearch:index`              |
| Index the site from the Panel              | Janitor field with `algolia-docsearch:index` |
| Keep the index current on every edit       | `hooks` option                               |
| Index unlisted pages, too                  | `filter` option                              |
| Index a field instead of the rendered HTML | `content` closure                            |
| Add data to each search hit                | `attributes` option                          |
| Tune Algolia's index settings              | `indexSettings` option                       |
| Index from my own code                     | `algolia()->index()`                         |

## Features

### 🔦 Indexing Without the Crawler

The plugin renders every listed page of the configured templates, extracts the text of one HTML tag, and writes one DocSearch record per page. Run the [Kirby CLI](https://github.com/getkirby/cli) command to replace the index with the current state of the site:

```bash
kirby algolia-docsearch:index
```

With [Janitor](https://github.com/bnomei/kirby-janitor) installed, the same command runs from a button in the Panel:

```yml
fields:
  algoliaIndex:
    label: johannschopplich.algolia-docsearch.index.start
    type: janitor
    command: algolia-docsearch:index
```

### 🔁 Page Hooks

With the `hooks` option enabled, the index follows changes in the Panel. Index the site once, and the hooks keep it current from there:

- Saving content or changing the title in a translation updates that language's index. In the default language, it updates every language's index, since translations show its content for fields they don't translate.
- Changing the slug or status, moving, changing the template, and deleting update every language's index. When a page's URL or draft state changes, its descendants follow.

The hooks stay off while Kirby's `debug` option is on.

### 🌐 One Index per Language

On a multi-language site, each language gets its own index: the `index` option plus the language code, e.g. `example-en` and `example-de`. Labels take one string per language:

```php
'label' => [
    'default' => [
        'en' => 'Page',
        'de' => 'Seite'
    ]
]
```

### 🧩 Content From HTML or a Closure

By default, a record's content is the text inside the rendered page's `<body>`. Point the `content` option to another tag, such as `main`, or return the text from a closure. This one indexes a blocks field instead of the rendered page:

```php
'content' => fn (\Kirby\Cms\Page $page, string|null $languageCode) => strip_tags($page->content($languageCode)->get('text')->toBlocks()->toHtml())
```

The closure receives the language code of the index it writes to. For different content per template, pass an array of closures keyed by template name, with `default` for the rest.

## Requirements

- Kirby 5

## Installation

### Composer (Recommended)

```bash
composer require johannschopplich/kirby-algolia-docsearch
```

### Manual Installation

Download a [release](https://github.com/johannschopplich/kirby-algolia-docsearch/releases) and extract it to `/site/plugins/kirby-algolia-docsearch`.

## DocSearch Setup

The plugin only fills the index. The search button and modal come from the DocSearch library, which you add to your frontend:

```bash
npm install @docsearch/js @docsearch/css
```

```js
import docsearch from "@docsearch/js/docsearch";
import "@docsearch/css";

docsearch({
  container: "#docsearch",
  appId: "YOUR_APP_ID",
  apiKey: "YOUR_SEARCH_API_KEY",
  indices: ["example"],
});
```

The `@docsearch/js/docsearch` entry is keyword search only. Use your Algolia Search API key here, never the key from the plugin options. On a multi-language site, pick the index of the current language, e.g. from the `lang` attribute of your `<html>` element:

```js
indices: [`example-${document.documentElement.lang}`],
```

See the [DocSearch documentation](https://docsearch.algolia.com) for the CDN build and [styling](https://docsearch.algolia.com/docs/styling).

## Options

All options are namespaced under `johannschopplich.algolia-docsearch`:

| Option                 | Default      | Description                                                                                                                                                                   |
| ---------------------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `appId`                | –            | Algolia application ID. Required.                                                                                                                                             |
| `apiKey`               | –            | Algolia API key with write access, such as the Admin API key. It stays on the server. Required.                                                                               |
| `index`                | –            | Index name. Multi-language sites get one index per language, suffixed with the language code. Required.                                                                       |
| `templates`            | `[]`         | Templates to index. Pages of other templates are left out, so nothing is indexed until you set this.                                                                          |
| `filter`               | listed pages | Closure `fn (Page $page): bool` that decides which pages of those templates get indexed. Drafts never are.                                                                    |
| `exclude.pages`        | `[]`         | Page IDs to leave out, as case-insensitive regular expressions matched against the whole ID, e.g. `blog/.*`.                                                                  |
| `content`              | `body`       | HTML tag whose text becomes the record's content, a closure `fn (Page $page, string\|null $languageCode): string`, or an array of closures per template with a `default` key. |
| `label.default`        | –            | Hit label (DocSearch's `lvl0`) as a string or an array per language code. Required unless `label.templates` covers every template.                                            |
| `label.templates`      | `[]`         | Labels per template, in the same shapes as `label.default`.                                                                                                                   |
| `attributes`           | `[]`         | Extra record fields as closures `fn (Page $page, string\|null $languageCode)`, keyed by field name.                                                                           |
| `searchableAttributes` | `[]`         | Attributes appended to DocSearch's searchable attributes.                                                                                                                     |
| `indexSettings`        | `[]`         | Algolia index settings. Each key replaces the plugin's value for that setting, including what `searchableAttributes` and `attributes` add to it.                              |
| `hooks`                | `false`      | Update the index on page changes in the Panel. See [Page Hooks](#-page-hooks).                                                                                                |

Example `config.php`:

```php
return [
    'johannschopplich.algolia-docsearch' => [
        'appId' => 'ALGOLIA_APP_ID',
        'apiKey' => 'ALGOLIA_ADMIN_API_KEY',
        'index' => 'example',
        'templates' => ['default', 'article', 'project'],
        'filter' => fn (\Kirby\Cms\Page $page) => $page->isListed() || $page->isUnlisted(),
        'exclude' => [
            'pages' => ['blog/drafts-.*']
        ],
        'content' => 'main',
        'label' => [
            'default' => 'Page',
            'templates' => [
                'article' => 'Article',
                'project' => 'Project'
            ]
        ],
        'indexSettings' => [
            'ranking' => ['words', 'typo', 'attribute', 'proximity', 'exact', 'custom']
        ],
        'hooks' => true
    ]
];
```

## Limits

- **One record per page.** Algolia's free plan rejects records larger than 10 KB. For long pages, shorten the text a `content` closure returns, e.g. with `Str::short($text, 5000)`.
- **Custom attributes need a request.** DocSearch asks for its own list of attributes with every search. To receive the fields from `attributes` in your hits, list them in `searchParameters.attributesToRetrieve` of the index entry in `indices`, together with DocSearch's own: `hierarchy.lvl0` to `hierarchy.lvl6`, `content`, `type`, and `url`.

## Credits

- [Wolf-Dieter Grabner](https://photos.flowlabs.studio) for sponsoring the initial version of this plugin.
- Lukas Bestle and Nico Hoffmann for the [Algolia integration](https://github.com/getkirby/getkirby.com/tree/main/site/plugins/search) of getkirby.com.

## License

[MIT](./LICENSE) License © 2023-PRESENT [Johann Schopplich](https://github.com/johannschopplich)

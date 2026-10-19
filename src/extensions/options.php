<?php

use Kirby\Cms\Page;

return [
    'templates' => [],
    'filter' => fn (Page $page): bool => $page->isListed(),
    'exclude' => [
        'pages' => []
    ],
    'content' => 'body',
    'label' => [
        'templates' => []
    ],
    'attributes' => [],
    'searchableAttributes' => [],
    'indexSettings' => [],
    'hooks' => false
];

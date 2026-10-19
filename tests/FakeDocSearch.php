<?php

declare(strict_types = 1);

use JohannSchopplich\Algolia\DocSearch;

/**
 * `DocSearch` with in-memory indices, installed as the singleton the hooks use.
 */
final class FakeDocSearch extends DocSearch
{
    /** @var array<string, FakeSearchIndex> */
    public array $indices = [];

    public static function install(): self
    {
        return self::$instance = new self();
    }

    public static function uninstall(): void
    {
        self::$instance = null;
    }

    public function getAlgoliaIndex(string|null $languageCode = null): FakeSearchIndex
    {
        return $this->indices[$this->indexName($languageCode)] ??= new FakeSearchIndex();
    }

    /**
     * @return list<string>
     */
    public function objectIds(string $indexName): array
    {
        $objectIds = array_keys($this->indices[$indexName]->records ?? []);
        sort($objectIds);

        return $objectIds;
    }
}

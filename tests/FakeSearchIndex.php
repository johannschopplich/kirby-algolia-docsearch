<?php

declare(strict_types = 1);

use Algolia\AlgoliaSearch\SearchIndex;

/**
 * Algolia index held in memory instead of sending requests.
 */
final class FakeSearchIndex extends SearchIndex
{
    /** @var array<string, array> */
    public array $records = [];

    public array $settings = [];

    public function __construct()
    {
    }

    public function saveObject($object, $requestOptions = [])
    {
        $this->records[$object['objectID']] = $object;
    }

    public function saveObjects($objects, $requestOptions = [])
    {
        foreach ($objects as $object) {
            $this->saveObject($object);
        }
    }

    public function replaceAllObjects($objects, $requestOptions = [])
    {
        $this->records = [];
        $this->saveObjects($objects);
    }

    public function deleteObject($objectId, $requestOptions = [])
    {
        unset($this->records[$objectId]);
    }

    public function deleteObjects($objectIds, $requestOptions = [])
    {
        foreach ($objectIds as $objectId) {
            $this->deleteObject($objectId);
        }
    }

    public function setSettings($settings, $requestOptions = [])
    {
        $this->settings = $settings;
    }
}

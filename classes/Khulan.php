<?php

namespace Bnomei;

use Kirby\Cms\Collection;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Language;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Cms\Users;
use MongoDB\BSON\Regex;

class Khulan
{
    /** Rebuild only materialized relations that name this target. */
    public static function refreshReferences(array $target): void
    {
        $prefix = $target['modelType'].'://';
        $keys = [$prefix.$target['id']];
        if (isset($target['uuid'])) {
            $keys[] = $prefix.$target['uuid'];
        }
        $collection = Mongodb::singleton()->contentCollection();
        foreach ($collection->find(['_references' => ['$in' => $keys], 'language' => $target['language']]) as $source) {
            $storage = self::documentToModel($source)?->storage();
            if ($storage instanceof KhulanStorage) {
                $storage->indexLanguage(Language::ensure($target['language'] ?? 'default'));
            }
        }
    }

    public static function removeDocuments(array $filter): void
    {
        $collection = Mongodb::singleton()->contentCollection();
        $targets = $collection->find($filter, ['typeMap' => ['root' => 'array'], 'projection' => ['id' => 1, 'uuid' => 1, 'language' => 1, 'modelType' => 1]])->toArray();
        $collection->deleteMany($filter);
        foreach ($targets as $target) {
            self::refreshReferences($target);
        }
    }

    public static function refreshPage(Page $page, ?Page $old = null): void
    {
        if (! option('bnomei.mongodb.khulan.write')) {
            return;
        }

        $treeChanged = $old === null || $old->id() !== $page->id();
        $optedOut = $old && KhulanStorage::supports($old) && ! KhulanStorage::supports($page);
        if ($old && ($treeChanged || $optedOut)) {
            self::removeDocuments([
                '$or' => [['modelType' => 'page'], ['modelType' => 'file', 'parentType' => 'page']],
                'id' => new Regex('^'.preg_quote($old->id()).'(/|$)'),
            ]);
        }

        $pages = new Pages([$page]);
        if ($treeChanged || $optedOut) {
            $pages = $pages->index(true);
        }
        foreach ([$pages, $pages->files()] as $models) {
            foreach ($models as $model) {
                if (($storage = $model->storage()) instanceof KhulanStorage) {
                    $storage->indexLanguages();
                }
            }
        }
    }

    public static function index(int $iterations = 2): array
    {
        $count = 0;
        $time = microtime(true);

        foreach (kirby()->models() as $model) {
            if (($storage = $model->storage()) instanceof KhulanStorage) {
                $storage->indexLanguages();
                $count++;
            }
        }

        // call twice by default to make it possible to resolve relations
        if ($iterations > 1) {
            self::index($iterations - 1);
        }

        // add indexes for better performance
        // https://learn.mongodb.com/learn/course/mongodb-indexes/lesson-4-working-with-compound-indexes-in-mongodb/learn
        // equality, sort, range + likely selected fields to avoid full collection scans
        if ($iterations === 1) {
            $indexes = [
                // by id
                ['id' => 1, 'template' => 1, 'status' => 1, 'num' => 1, 'slug' => 1, 'modified' => 1, 'title' => 1, 'uuid' => 1],
                ['id' => 1, 'template' => 1, 'sort' => 1, 'modified' => 1, 'filename' => 1,  'parent{}' => 1, 'uuid' => 1],
                // by uuid
                ['uuid' => 1, 'template' => 1, 'status' => 1, 'num' => 1, 'slug' => 1, 'modified' => 1, 'title' => 1, 'id' => 1],
                ['uuid' => 1, 'template' => 1, 'sort' => 1, 'modified' => 1, 'filename' => 1,  'parent{}' => 1, 'id' => 1],
                // by template and status
                ['template' => 1, 'status' => 1, 'num' => 1, 'slug' => 1, 'modified' => 1, 'title' => 1, 'id' => 1, 'uuid' => 1],
                ['template' => 1, 'sort' => 1, 'modified' => 1, 'filename' => 1, 'id' => 1, 'uuid' => 1, 'parent{}' => 1],
                // by email
                ['email' => 1, 'role' => 1, 'name' => 1, 'modified' => 1, 'id' => 1, 'uuid' => 1],
            ];
            $isMultilang = kirby()->multilang();
            foreach ($indexes as $index) {
                if ($isMultilang) {
                    $index['language'] = 1;
                }
                Mongodb::singleton()->contentCollection()->createIndex($index);
            }
            Mongodb::singleton()->contentCollection()->createIndex(['_references' => 1, 'language' => 1]);
        }

        return ['count' => $count, 'time' => microtime(true) - $time];
    }

    public static function flush(): bool
    {
        Mongodb::singleton()->contentCollection()->drop();

        return true;
    }

    /** @return Collection<Page|File|User|Site>|Pages<Page>|Files<File>|Users<User>|null */
    public static function documentsToModels(iterable $documents): Collection|Pages|Files|Users|null
    {
        $pages = $files = $users = $models = [];
        foreach ($documents as $document) {
            $model = self::documentToModel($document);
            if ($model === null) {
                continue;
            }
            $models[] = $model;
            match (true) {
                $model instanceof Page => $pages[] = $model,
                $model instanceof File => $files[] = $model,
                $model instanceof User => $users[] = $model,
                default => null,
            };
        }

        return match (true) {
            $models === [] => null,
            $models === $pages => new Pages($pages),
            $models === $files => new Files($files),
            $models === $users => new Users($users),
            default => new Collection($models),
        };
    }

    public static function documentToModel(mixed $document = null): Page|File|User|Site|null
    {
        if (! is_iterable($document)) {
            return null;
        }
        $document = iterator_to_array($document);
        $id = $document['id'] ?? $document['uuid'] ?? null;

        return match ($document['modelType'] ?? null) {
            'site' => kirby()->site(),
            'page' => is_string($id) ? kirby()->page($id) : null,
            'user' => is_string($id) ? kirby()->user($id) : null,
            'file' => ! is_string($id) ? null : (($document['parentType'] ?? null) === 'user'
                ? kirby()->user(dirname($id))?->file(basename($id)) : kirby()->file($id)),
            default => null,
        };
    }
}

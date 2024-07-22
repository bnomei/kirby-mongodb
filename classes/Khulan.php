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
use Kirby\Data\Data;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

class Khulan
{
    public static function index(int $iterations = 2): array
    {
        $count = 0;
        $time = microtime(true);

        /** @var KhulanPage $page */
        foreach (site()->index(true) as $page) {
            if ($page->hasKhulan() !== true) {
                continue;
            }
            if (kirby()->multilang()) {
                /** @var Language $language */
                foreach (kirby()->languages() as $language) {
                    $content = $page->content($language->code())->toArray();
                    $page->writeKhulan($content, $language->code());
                }
            } else {
                $page->writeKhulan($page->content()->toArray());
            }
            $count++;
        }

        /** @var KhulanUser $user */
        foreach (kirby()->users() as $user) {
            if ($user->hasKhulan() !== true) {
                continue;
            }
            if (kirby()->multilang()) {
                /** @var Language $language */
                foreach (kirby()->languages() as $language) {
                    $content = $user->content($language->code())->toArray();
                    $user->writeKhulan($content, $language->code());
                }
            } else {
                $user->writeKhulan($user->content()->toArray());
            }
            $count++;
        }

        /** @var KhulanFile $file */
        foreach (site()->index(true)->files() as $file) {
            if ($file->hasKhulan() !== true) {
                continue;
            }
            if (kirby()->multilang()) {
                /** @var Language $language */
                foreach (kirby()->languages() as $language) {
                    $contentFile = $file->root().'.'.$language->code().'.txt';
                    if (! F::exists($contentFile)) {
                        continue;
                    }
                    $file->writeKhulan(Data::read($contentFile), $language->code());
                }
            } else {
                $contentFile = $file->root().'.txt';
                $file->writeKhulan(Data::read($contentFile));
            }
            $count++;
        }

        $meta = [
            'count' => $count,
            'time' => microtime(true) - $time,
        ];

        // call twice by default to make it possible to resolve relations
        if ($iterations > 1) {
            $iterations--;
            $meta = self::index($iterations);
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
                khulan()->createIndex($index, ['unique' => true]);
            }
        }

        return $meta;
    }

    public static function flush(): bool
    {
        Mongodb::singleton()->contentCollection()->drop();

        return true;
    }

    public static function documentsToModels(iterable $documents): Collection|Pages|Files|Users|null
    {
        $documents = iterator_to_array($documents);

        if (empty($documents)) {
            return null;
        }

        $models = [];

        foreach ($documents as $document) {
            $models[] = self::documentToModel($document);
        }

        $models = array_filter($models, function ($obj) {
            return $obj !== null;
        });

        $modelTypes = array_count_values(array_map(function ($document) {
            return $document['modelType'];
        }, $documents));

        if (count($modelTypes) === 1) {
            $modelType = array_key_first($modelTypes);
            if ($modelType === 'file') {
                return new Files($models);
            } elseif ($modelType === 'user') {
                return new Users($models);
            } elseif ($modelType === 'page') {
                return new Pages($models);
            }
        } else {
            return new Collection($models);
        }

        return null;
    }

    public static function documentToModel(mixed $document = null): Page|File|User|Site|null
    {
        if (! $document) {
            return null;
        }

        if ($document['modelType'] === 'file') {
            return kirby()->file($document['id']);
        } elseif ($document['modelType'] === 'user') {
            return kirby()->user($document['id']);
        } elseif ($document['modelType'] === 'site') {
            return kirby()->site();
        } elseif ($document['modelType'] === 'page') {
            $document = iterator_to_array($document);
            $id = A::get($document, 'id', A::get($document, 'uuid'));

            return kirby()->page($id);
        }

        return null;
    }
}

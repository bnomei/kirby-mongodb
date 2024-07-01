<?php

namespace Bnomei;

use Kirby\Cms\Collection;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Cms\Users;
use Kirby\Toolkit\A;

class Khulan
{
    public static function index(int $iterations = 2): array
    {
        $count = 0;
        $hash = [];

        // reading a field like the title will make sure
        // that the page is loaded and cached
        /** @var Page $page */
        foreach (site()->index(true) as $page) {
            if ($page->hasKhulan() !== true) {
                continue;
            }
            if (kirby()->multilang()) {
                foreach (kirby()->languages() as $language) {
                    $content = $page->content($language->code())->toArray();
                    $hash[] = $content['title'];
                    $page->writeKhulan($content, $language->code());
                }
            } else {
                $hash[] = $page->title()->value();
                $page->writeKhulan($page->content()->toArray());
            }
            $count++;
        }
        // TODO: files, users

        $meta = [
            'count' => $count,
            'hash' => hash('xxh3', implode('|', $hash)),
        ];

        // call twice by default to make it possible to resolve relations
        if ($iterations > 1) {
            $iterations--;
            $meta = self::index($iterations);
        }

        // add indexes for better performance
        if ($iterations === 1) {
            khulan()->createIndex(['id' => 1]);
            khulan()->createIndex(['uuid' => 1]);
            khulan()->createIndex(['language' => 1]);
            khulan()->createIndex(['template' => 1]);
            khulan()->createIndex(['modelType' => 1]);
        }

        return $meta;
    }

    public static function flush(): bool
    {
        mongo()->contentCollection()->drop();

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

    public static function documentToModel($document = null): Page|File|User|Site|null
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

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
    public static function index(): array
    {
        $count = 0;
        $hash = [];

        // reading a field like the title will make sure
        // that the page is loaded and cached
        foreach (site()->index(true) as $page) {
            $hash[] = $page->title()->value();
            $count++;
        }
        // TODO: files, users

        return [
            'count' => $count,
            'hash' => hash('xxh3', implode('|', $hash)),
        ];
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
            $id = A::get($document, 'uuid', A::get($document, 'id'));

            return kirby()->page($id);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

use Bnomei\Khulan;
use Bnomei\KhulanStorage;
use Bnomei\Mongodb;
use Kirby\CLI\CLI;
use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\Storage;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;

@include_once __DIR__.'/vendor/autoload.php';

load([
    'bnomei\\mongodb' => 'classes/Mongodb.php',
    'bnomei\\khulan' => 'classes/Khulan.php',
    'bnomei\\khulandocument' => 'classes/KhulanDocument.php',
    'bnomei\\khulanstorage' => 'classes/KhulanStorage.php',
    'bnomei\\modelwithkhulan' => 'classes/ModelWithKhulan.php',
], __DIR__);

if (! function_exists('mongo')) {
    function mongo(?string $collection = null): Mongodb|Collection
    {
        if (! $collection) {
            return Mongodb::singleton();
        }

        return Mongodb::singleton()->collection($collection);
    }
}

if (! function_exists('khulan')) {

    function khulan(string|array|null $search = null, ?array $options = null): mixed
    {
        $collection = Mongodb::singleton()->contentCollection();

        // only get these fields as it is faster and enough for kirby
        $options ??= [
            'projection' => [
                'id' => 1,
                'uuid' => 1,
                'modelType' => 1,
                'parentType' => 1,
            ],
        ];

        if (is_array($search)) {
            return Khulan::documentsToModels($collection->find($search, $options));

        } elseif (is_string($search)) {
            return Khulan::documentToModel($collection->findOne([
                '$or' => [
                    ['_id' => preg_match('/^[a-f0-9]{24}$/iD', $search) ? new ObjectId($search) : $search],
                    ['id' => $search],
                    ['uuid' => $search],
                    ['email' => $search], // user
                ],
            ], $options));
        }

        return $collection;
    }
}

Kirby::plugin('bnomei/mongodb', [
    'options' => [
        // plugin
        'cache' => true,

        // mongodb
        'host' => '127.0.0.1',
        'port' => 27017,
        'database' => 'kirby',
        'username' => null,
        'password' => null,
        'uriOptions' => [],
        'driverOptions' => [],
        'auto-clean-cache' => true,

        // collections
        'collections' => [
            'cache' => 'cache',
            'content' => 'kirby',
        ],

        // khulan
        'khulan' => [ // cache for models
            'read' => false, // mongodb is most likely slower than file system for the pages
            'write' => true,
            'patch-files-class' => true, // include file metadata for opted-in parents
        ],
    ],
    'cacheTypes' => [
        'mongodb' => Mongodb::class,
    ],
    'components' => [
        'storage' => static function (App $kirby, ModelWithContent $model): Storage {
            return KhulanStorage::supports($model)
                ? new KhulanStorage($model)
                : new PlainTextStorage($model);
        },
    ],
    'commands' => [
        'khulan:index' => [
            'description' => 'Force indexing all pages/files/users',
            'args' => [],
            'command' => static function (CLI $cli): void {
                $meta = Khulan::index();
                $count = $meta['count'];
                $time = round($meta['time'] * 1000);

                $cli->success('Indexed '.$count.' in '.$time.'ms.');

                if (function_exists('janitor')) {
                    janitor()->data($cli->arg('command'), [
                        'status' => 200,
                        'message' => 'Indexed '.$count.' in '.$time.'ms.',
                    ]);
                }
            },
        ],
    ],
    'hooks' => [
        // These actions can move directories or update model metadata without a content write.
        ...array_fill_keys(['page.changeStatus:after', 'page.changeNum:after', 'page.changeSlug:after', 'page.changeTemplate:after', 'page.move:after'], function ($newPage, $oldPage) {
            Khulan::refreshPage($newPage, $oldPage);
        }),
        'page.duplicate:after' => function ($duplicatePage) {
            Khulan::refreshPage($duplicatePage);
        },
        'file.changeName:after' => function ($newFile, $oldFile) {
            if (($storage = $newFile->storage()) instanceof KhulanStorage && option('bnomei.mongodb.khulan.write')) {
                Khulan::removeDocuments(['id' => $oldFile->id(), 'modelType' => 'file', 'parentType' => $oldFile->parent()::CLASS_ALIAS]);
                $storage->indexLanguages();
            }
        },
        ...array_fill_keys(['user.changeName:after', 'user.changeEmail:after', 'user.changeRole:after'], function ($newUser) {
            if (($storage = $newUser->storage()) instanceof KhulanStorage) {
                $storage->indexLanguages();
            }
        }),
        'system.loadPlugins:after' => function () {
            if ((option('bnomei.mongodb.khulan.read') ||
                option('bnomei.mongodb.khulan.write')) &&
                Mongodb::singleton()->contentCollection()->countDocuments() === 0) {
                Khulan::index();
            }
        },
    ],
]);

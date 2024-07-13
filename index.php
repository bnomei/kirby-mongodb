<?php

declare(strict_types=1);

use Bnomei\Khulan;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;
use MongoDB\Collection;

@include_once __DIR__.'/vendor/autoload.php';

load([
    'bnomei\\mongodb' => 'classes/Mongodb.php',
    'bnomei\\khulan' => 'classes/Khulan.php',
    'bnomei\\modelwithkhulan' => 'classes/ModelWithKhulan.php',
], __DIR__);

if (! function_exists('mongo')) {
    function mongo(?string $collection = null): \Bnomei\Mongodb|Collection
    {
        if (! $collection) {
            return \Bnomei\Mongodb::singleton();
        }

        return \Bnomei\Mongodb::singleton()->collection($collection);
    }
}

if (! function_exists('khulan')) {

    function khulan(string|array|null $search = null, ?array $options = null): mixed
    {
        $collection = \Bnomei\Mongodb::singleton()->contentCollection();

        // only get these fields as it is faster and enough for kirby
        $options ??= [
            'projection' => [
                'id' => 1,
                'uuid' => 1,
                'modelType' => 1,
            ],
        ];

        if (is_array($search)) {
            return Khulan::documentsToModels($collection->find($search, $options));

        } elseif (is_string($search)) {
            return Khulan::documentToModel($collection->findOne([
                '$or' => [
                    ['_id' => $search],
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
            'patch-files-class' => true, // monkey patch files class
        ],
    ],
    'cacheTypes' => [
        'mongodb' => \Bnomei\Mongodb::class,
    ],
    'commands' => [
        'khulan:index' => [
            'description' => 'Force indexing all pages/files/users',
            'args' => [],
            'command' => static function (\Kirby\CLI\CLI $cli): void {
                $meta = Khulan::index(1);
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
        'system.loadPlugins:after' => function () {
            if ((option('bnomei.mongodb.khulan.read') ||
                option('bnomei.mongodb.khulan.write')) &&
                khulan()->countDocuments() === 0) {
                Khulan::index();
            }
            if (option('bnomei.mongodb.khulan.patch-files-class')) {
                $filesClass = kirby()->roots()->kirby().'/src/Cms/Files.php';
                if (F::exists($filesClass) && F::isWritable($filesClass)) {
                    $code = F::read($filesClass);
                    if (Str::contains($code, '\Bnomei\KhulanFile::factory') === false) {
                        $code = str_replace('File::factory(', '\Bnomei\KhulanFile::factory(', $code);
                        F::write($filesClass, $code);
                    }
                }
            }
        },
    ],
]);

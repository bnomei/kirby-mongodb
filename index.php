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

    function khulan(string|array|null $search = null): mixed
    {
        $collection = \Bnomei\Mongodb::singleton()->contentCollection();

        if (is_array($search)) {
            return Khulan::documentsToModels($collection->find($search));

        } elseif (is_string($search)) {
            return Khulan::documentToModel($collection->findOne([
                '$or' => [
                    ['_id' => $search],
                    ['id' => $search],
                    ['uuid' => $search],
                    ['email' => $search], // user
                ],
            ]));
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

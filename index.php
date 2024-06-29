<?php

declare(strict_types=1);

use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;
use MongoDB\Client;

@include_once __DIR__.'/vendor/autoload.php';

load([
    'bnomei\\mongodb' => 'classes/Mongodb.php',
    'bnomei\\khulan' => 'classes/Khulan.php',
], __DIR__);

if (! function_exists('mongodb')) {
    function mongodb(array $options = []): Client
    {
        return \Bnomei\Mongodb::singleton($options)->client();
    }
}

Kirby::plugin('bnomei/mongodb', [
    'options' => [
        // plugin
        'cache' => true,

        'khulan' => [ // cache for models
            'read' => true,
            'write' => true,
            'patch-files-class' => true, // monkey patch files class
        ],

        // mongodb
        'host' => '127.0.0.1',
        'port' => 27017,
    ],
    'cacheTypes' => [
        'mongodb' => \Bnomei\Mongodb::class,
    ],
    'hooks' => [
        'system.loadPlugins:after' => function () {
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

<?php

return [
    'debug' => false, // must be off for cache to stick

    'languages' => true,

    'content' => [
        'locking' => false,
    ],

    'bnomei.mongodb.database' => 'khulan_test', // disposable database for the integration suite
    'bnomei.mongodb.khulan.patch-files-class' => true, // index metadata for files of opted-in parents
    'bnomei.mongodb.khulan.read' => true, // for unit-testing only
    'bnomei.mongodb.khulan.write' => true, // always write to mongodb
];

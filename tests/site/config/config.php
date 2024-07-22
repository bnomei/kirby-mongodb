<?php

return [
    'debug' => false, // must be off for cache to stick

    'languages' => true,

    'content' => [
        'locking' => false,
    ],

    'bnomei.mongodb.khulan.patch-files-class' => true, // do the monkey patch
    'bnomei.mongodb.khulan.read' => true, // for unit-testing only
    'bnomei.mongodb.khulan.write' => true, // always write to mongodb
];

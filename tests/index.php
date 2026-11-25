<?php

const KIRBY_HELPER_DUMP = false;
const KIRBY_HELPER_E = false;

require __DIR__.'/../vendor/autoload.php';
new Kirby(['roots' => ['index' => __DIR__]]);

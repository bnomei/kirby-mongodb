<?php

use Bnomei\Mongodb;

$results = Mongodb::singleton()->benchmark(1000);
var_dump($results);

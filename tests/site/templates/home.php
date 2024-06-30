<?php

$results = \Bnomei\Mongodb::singleton()->benchmark(1000);
var_dump($results);

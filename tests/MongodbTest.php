<?php

use Bnomei\Khulan;
use Bnomei\Mongodb;
use Kirby\Cms\Page;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;

beforeEach(function () {
    mongo()->cacheCollection()->deleteMany([]); // cleanup
    mongo()->contentCollection()->deleteMany([]); // cleanup
});

it('can has an singleton', function () {
    $mongodb = Mongodb::singleton();
    expect($mongodb)->toBeInstanceOf(Mongodb::class);
});

it('can have options', function () {
    Mongodb::$singleton = null;
    $mongodb = Mongodb::singleton([
        'debug' => true, // default is false from config
    ]);

    expect($mongodb->option('debug'))->toBe(true);

    Mongodb::$singleton = null;
});

it('can get the `kirby` collection', function () {
    $mongodb = mongo();
    $collection = $mongodb->collection('kirby');

    expect($collection)->toBeInstanceOf(Collection::class);
});

it('can create a mongodb document via the helper', function () {
    $insertOneResult = khulan()->insertOne([
        'name' => 'Alice',
        'age' => 25,
        'city' => 'New York',
    ]);

    expect($insertOneResult->getInsertedId())->toBeInstanceOf(ObjectId::class);
});

it('can create and read a document', function () {
    khulan()->insertOne([
        'name' => 'Alice',
        'age' => 25,
        'city' => 'New York',
    ]);

    expect(khulan()->countDocuments(['name' => 'Alice']))->toBe(1);

    $document = khulan()->findOne(['name' => 'Alice']);

    expect($document)->toBeInstanceOf(BSONDocument::class);
    expect($document['name'])->toBe('Alice');
});

it('can use the cache', function () {
    $mongodb = mongo();
    $mongodb->set('test', 'value');
    $value = $mongodb->get('test');

    expect($value)->toBe('value');
});

it('can use the cache with expiration', function () {
    $mongodb = mongo();
    $mongodb->set('test', 'value', 1);
    $value = $mongodb->get('test');

    expect($value)->toBe('value');
});

it('can find a page by id and uuid', function () {
    Khulan::index();

    // load page from cache
    $page = khulan('betterharder');

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->id())->toBe('betterharder');
});

it('can find all pages with the same template', function () {
    Khulan::index();

    $pages = khulan(['template' => 'default']);

    expect($pages->count())->toBe(2);
});

it('can find all pages that have a certain tags', function () {
    Khulan::index();

    $pages = khulan([
        'tags[,]' => ['$in' => ['Daft']],
    ]);

    expect($pages->count())->toBe(1);

    $pages = khulan([
        'tags[,]' => ['$in' => ['Daft', 'Punk']],
    ]);

    expect($pages->count())->toBe(2);

    $pages = khulan([
        'tags[,]' => ['$in' => ['Punk']],
    ]);

    expect($pages->count())->toBe(1);
});

it('can find all pages that have another page linked', function () {
    Khulan::index();

    $pages = khulan([
        'related[]' => ['$in' => ['page://fasterstronger']],
    ]);

    expect($pages->count())->toBe(1);
});

it('find all products in the category books or movies that had been modified within the last 7 days', function () {

    $fs = page('fasterstronger');
    $old = $fs->modified();
    $fs->save();
    expect($fs->modified())
        ->toBeGreaterThan($old)
        ->toBeGreaterThan(time() - (7 * 24 * 60 * 60));

    Khulan::index();

    $pages = khulan([
        'template' => 'default',
        'category[,]' => ['$in' => ['books', 'movies']],
        'modified' => ['$gt' => time() - (7 * 24 * 60 * 60)],
    ]);

    expect($pages->count())->toBe(1);
});

it('can run the benchmark', function () {
    mongo()->benchmark();
})->skip();

<?php

use Bnomei\Khulan;
use Bnomei\Mongodb;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Cms\Users;
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
        'uriOptions' => fn () => [],
    ]);

    expect($mongodb->option('debug'))->toBe(true)
        ->and($mongodb->option('uriOptions'))->toBeArray();

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

    expect($value)->toBe('value')
        ->and($mongodb->root())->toBeDirectory()
        ->and($mongodb->cache())->toBe($mongodb)
        ->and($mongodb->remove('test'))->toBeTrue()
        ->and($mongodb->get('test'))->toBeNull();
});

it('can use the cache for array based values', function () {
    $mongodb = mongo();
    $mongodb->set('tagging', [
        'tags' => ['tag1', 'tag2'],
        'not_value' => 'notmyvalue',
    ]);

    $collection = mongo()->cacheCollection();
    $documents = $collection->find([
        'value.tags' => ['$in' => ['tag1']],
    ]);

    foreach ($documents as $document) {
        expect($document->value->tags[0])->toEqual('tag1')
            ->and($document->value->tags[1])->toEqual('tag2')
            ->and($document->value->not_value)->toEqual('notmyvalue');
    }
});

it('can clean expired entries', function () {
    $mongodb = mongo();
    $mongodb->set('test', 'value', 5);

    $mongodb->clean(time() + 5 * 60 + 1);

    $value = $mongodb->get('test');

    expect($value)->toBeNull();
});

it('will not use the cache in debug mode', function () {
    Mongodb::$singleton = null;

    $mongodb = Mongodb::singleton([
        'debug' => true,
    ]);

    expect($mongodb->option('debug'))->toBeTrue()
        ->and($mongodb->option()['debug'])->toBeTrue();

    $mongodb->set('test', 'value');
    expect($mongodb->get('test'))->toBeNull();

    Mongodb::$singleton = null;
});

it('can use the cache with expiration', function () {
    $mongodb = mongo();
    $mongodb->set('test', 'value', 1);
    $value = $mongodb->get('test');

    expect($value)->toBe('value');
});

it('can use a closure in the cache', function () {
    $mongodb = mongo();
    $mongodb->getOrSet('swatch', fn () => date('B'));
    $value = $mongodb->get('swatch');

    expect($value)->toBeNumeric();
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

    $pages = khulan(['template' => 'default', 'language' => 'en']);

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

    expect($pages->count())->toBe(2);
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
        'language' => 'en',
    ]);

    expect($pages->count())->toBeGreaterThanOrEqual(1);
});

it('can find a user by email', function () {

    kirby()->impersonate('kirby');

    if ($user = kirby()->users()->find('test@bnomei.com') ?? null) {
        $user->delete(); // cleanup
    }

    Khulan::index();

    /** @var \Bnomei\KhulanUser $user */
    $user = kirby()->users()->create([
        'email' => 'test@bnomei.com',
        'role' => 'admin',
        'model' => 'admin',
        'password' => 'testtest',
    ]);
    // NOTE: both role and model are needed
    // for the user to be indexed
    $email = $user->email();

    $users = khulan(['email' => $email]);
    expect($users)->toBeInstanceOf(Users::class)
        ->and($users->first()->email())->toBe($email);

    $user = khulan($email);
    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email())->toBe($email);
});

it('can find a file', function () {
    Khulan::index();

    /** @var \Bnomei\KhulanFile $file */
    $file = kirby()->file('betterharder/image.jpg');
    expect($file)->toBeInstanceOf(\Kirby\Cms\File::class)
        ->and($file->filename())->toBe('image.jpg');

    $file = khulan('betterharder/image.jpg');
    expect($file)->toBeInstanceOf(\Kirby\Cms\File::class)
        ->and($file->filename())->toBe('image.jpg');
})->skipOnLinux('Does not work in CI for some reason');

it('will use the indices', function () {
    Khulan::index();

    $documents = khulan()->aggregate([
        ['$match' => [
            'template' => 'default',
            'status' => 'listed',
            'language' => 'en',
            'category[,]' => 'books',
        ]],
        ['$sort' => ['modified' => -1]],
        ['$limit' => 1],
    ]);
    $count = 0;
    foreach ($documents as $document) {
        $count++;
    }
    expect($count)->toBe(1);
});

it('can use options on the khulan-find to limit selected fields', function () {
    Khulan::index();

    // these are the default options for khulan
    // and optimized for speed
    $page = khulan('betterharder', [
        'projection' => [
            'id' => 1,
            'uuid' => 1,
            'modelType' => 1,
        ],
    ]);

    expect($page)->not()->toBeNull();
});

it('will remove the cache if a page gets deleted', function () {

    kirby()->impersonate('kirby');

    if ($page = page('home/rainbow')) {
        $page->delete(); // cleanup
    }

    Khulan::index();

    $page = page('home')->createChild([
        'slug' => 'rainbow',
        'template' => 'default',
        'content' => [
            'title' => 'Rainbow',
            'uuid' => 'rainbow',
        ],
    ]);

    $page = khulan('rainbow');
    expect($page)->not()->toBeNull();

    $page->delete();
    $page = khulan('rainbow');
    expect($page)->toBeNull();
});

it('can flush the content cache', function () {
    Khulan::index();

    $page = khulan('betterharder');
    expect($page)->not()->toBeNull();

    Khulan::flush();
    $page = khulan('betterharder');
    expect($page)->toBeNull();
});

it('can convert documents to models', function () {
    Khulan::index();

    $documents = khulan()->find([
        'template' => 'default',
        'status' => 'listed',
        'language' => 'en',
        'category[,]' => 'books',
    ]);

    $models = Khulan::documentsToModels($documents);
    expect($models)->toBeInstanceOf(\Kirby\Cms\Pages::class);
});

it('can encode and decode data for mongodb', function () {
    /** @var \Bnomei\KhulanPage $page */
    $page = page('betterharder');
    $data = $page->content()->toArray();
    $encoded = $page->encodeKhulan($data);
    $decoded = $page->decodeKhulan($encoded);

    expect($decoded)->toBe($data);
});

it('can run the benchmark', function () {
    mongo()->benchmark();
})->skip();

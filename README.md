# 🐎 Khulan - Kirby MongoDB

![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-mongodb?color=ae81ff)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-mongodb?color=272822)
[![Build Status](https://flat.badgen.net/travis/bnomei/kirby-mongodb)](https://travis-ci.com/bnomei/kirby-mongodb)
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby-mongodb)](https://codeclimate.com/github/bnomei/kirby-mongodb)
[![Twitter](https://flat.badgen.net/badge/twitter/bnomei?color=66d9ef)](https://twitter.com/bnomei)

Khulan is a cache driver and content cache with NoSQL interface for Kirby using MongoDB.

## Commercial Usage

> <br>
> <b>Support open source!</b><br><br>
> This plugin is free but if you use it in a commercial project please consider to sponsor me or make a donation.<br>
> If my work helped you to make some cash it seems fair to me that I might get a little reward as well, right?<br><br>
> Be kind. Share a little. Thanks.<br><br>
> &dash; Bruno<br>
> &nbsp; 

| M                                                    | O                                     | N                                               | E                                                   | Y                                            |
|------------------------------------------------------|---------------------------------------|-------------------------------------------------|-----------------------------------------------------|----------------------------------------------|
| [Github sponsor](https://github.com/sponsors/bnomei) | [Patreon](https://patreon.com/bnomei) | [Buy Me a Coffee](https://buymeacoff.ee/bnomei) | [Paypal dontation](https://www.paypal.me/bnomei/15) | [Hire me](mailto:b@bnomei.com?subject=Kirby) |

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby-mongodb/archive/master.zip) as folder `site/plugins/kirby-mongodb`
  or
- `git submodule add https://github.com/bnomei/kirby-mongodb.git site/plugins/kirby-mongodb` or
- `composer require bnomei/kirby-mongodb`

## MongoDB

There are various ways to install [MongoDB](https://www.mongodb.com/). This is one way to do it on localhost for Mac OSX
using Homebrew and MongoDB Atlas.

```sh
brew install mongodb-atlas
atlas setup # create account and sign-in
atlas deployments setup # -> select localhost
atlas deployments start # start the local mongodb
```

## Usecase

The plugin caches all content files and keeps the cache up to date when you add/remove or update content. This cache
will be used when constructing page/file/user objects making everything that involves model faster (even the
Panel).

It will also allow you to query the content cache directly as a NoSQL database which might be very useful for some
use-cases like filtering or searching content.

## Setup

For each template you want to be cached you need to use a model to add the content cache logic using a trait.

**site/models/default.php**

```php
class DefaultPage extends \Kirby\Cms\Page
{
    use \Bnomei\ModelWithKhulan;
}
```

> [!NOTE]
> You can also use the trait for user models. File models are patched automatically.

## Kirby's Content goes NoSQL

The plugin writes the content cache to a collection named `khulan` in the database. You can query this collection
directly. It is **not** wrapped in an Cache object. This allows you to treat all your Kirby content as a NoSQL database.

```php
// using the collection
$document = khulan()->find(['uuid' => 'XXX']);

// find a single page by uuid string
$page = khulan((string) $myIdOrUUID);

// find a single page by uuid by field-key
$page = khulan(['uuid' => 'page://betterharder']);

// find all pages with a template
$pages = khulan(['template' => 'post']);

// find a user by email or Id
$user = khulan($emailOrId);

// find a file by filename and template
$file = khulan(['filename' => 'my-image.jpg', 'template' => 'image']);
```

### Special Fields with [], {} and [,]

The plugin creates modified copies of a few field types to make the YAML based content from Kirby ready for queries.
You can use

- `fieldname[]` to query for a value in an array from pages/files/user fields and
- `fieldname{}` to query for an objectId in an array from pages/files/user fields and
- `fieldname[,]` to query for fields in comma separated strings like tags/select/radio/checkbox/multiselect fields.

```php
// find all pages that have another page linked
$pages = khulan([
    'related[]' => ['$in' => ['page://fasterstronger']],
]);
$pages = khulan([
    'related{}' => ['$in' => ['dc9f7835c2400cc4']], // objectId
]);

// find all products in the category 'books' or 'movies'
// that had been modified within the last 7 days
$pages = khulan([
    'template' => 'product',
    'category[,]' => ['$in' => ['books', 'movies']],
    'modified' => ['$gt' => time() - (7 * 24 * 60 * 60)]
]);
```

### Example: Filtering and Resolving Relations

With Kirby's content cache as a NoSQL database you can do some advanced filtering. If you would do the same with Kirby's
filtering on collection you would end up loading a lot pages and that is not as efficient. We can load the information
we need directly from the cache without the need to load the full page object.

Why would we bother to read the content from a NoSQL based cache and not just put a cache around the native Kirby logic?
Because the hard thing with caches is to know when to invalidate them. With the content cache we can query the NoSQL
based cache directly and updating any model will be reflected instantly. Whereas with a cache around the native Kirby
logic we would need rebuild the cache on every change (like with
the [pages cache](https://getkirby.com/docs/guide/cache#caching-pages)).

Let's assume we have two models: `film` and `actor`. The `film` model has a field `actors` which is a pages field with
linked `actor` pages. We want to list all films with their actors.

#### Load 1000 films and their actors, total of 6429 pages accessed in 250ms

```php
$films = page('films')->children();
foreach ($films as $film) {
    echo $film->title();
    $actors = [];
    foreach ($film->actors()->toPages() as $actor) {
        $actors[] = $actor->title();
    }
    echo implode(', ', $actors);
}
```

#### Query the cache instead to get the same information in under 100ms

```php
/** @var \MongoDB\Driver\Cursor $films */
$films = khulan()->aggregate([
    [
        // only get pages with template 'film'
        '$match' => ['template' => 'film'],
    ],
    [
        // lookup actors by their mongodb objectId
        // this will create an array of objects
        '$lookup' => [
            'from' => 'kirby',
            'localField' => 'actors{}',
            'foreignField' => '_id',
            'as' => 'actor_details',
        ],
    ],
    [
        // only get the fields we need
        // to make the query even faster
        '$project' => [
            'title' => 1,
            'id' => 1,
            'actor_details.title' => 1,
        ],
    ],
]);

/** @var \MongoDB\BSON\Document $film */
foreach ($films as $film) { ?>
<div>
    <a href="<?= url($film->id) ?>"><?= html($film->title) ?></a>
    <ul>
        <?php foreach ($film->actor_details as $actor) { ?>
            <li><?= html($actor->title) ?></li>
        <?php } ?>
    </ul>
</div>
<?php }
```

> [!NOTE]
>
> This example is from [my Sakila DB kit](https://github.com/bnomei/kirby-sakila-kit/tree/with-mongodb-plugin). You can
> use similar queries to filter and resolve relations of files and user objects.

## MongoDB Client

You can access the underlying MongoDB client directly.

```php
/** @var \MongoDB\Client $client */
$client = \Bnomei\Mongodb::singleton()->client();
$client = mongo()->client();

$collection = $client->selectCollection('my-database', 'my-collection');
```

## Cache

You can either use the **cache** directly or use it as a **cache driver** for Kirby.

The MongoDB based cache will, compared to the default file-based cache, perform **worse**! This is to be expected as
web-servers are optimized for handling requests to a couple of hundred files and keep them in memory.

```php
$cache = mongo()->cache();

$cache->set('mykey', 'myvalue', 5); // ttl in minutes
$value = $cache->get('mykey');

$cache->set('tagging', [
    'tags' => ['tag1', 'tag2'],
    'value' => 'myvalue',
]);
```

As with regular Kirby cache you can also use the `getOrSet` method to wrap your time expensive code into a closure and
only execute it when the cache is empty.

```php
$cache = mongo()->cache();

$value = $cache->getOrSet('mykey', function() {
    sleep(5); // like a API call, database query or filtering content
    return 'myvalue';
}, 5); 
```

Using the MongoDB-based cache will allow you to perform NoSQL queries on the cache and do advanced stuff like
filtering my tags or invalidating many cache entries at once.

```php
// NOTE: we are using the cacheCollection() method here
$collection = mongo()->cacheCollection();

// find all that have the tag 'tag1'
$documents = $collection->find([
    'tags' => ['$in' => ['tag1']],
]);

// delete any cache entry older than 5 minutes
$deleteResult = $collection->deleteMany([
    'expires_at' => ['$lt' => time() - 5*60]
]);
$deletedCount = $deleteResult->getDeletedCount();
```

## Using the Cache Driver in Kirby

You can also use the MongoDB-based cache as a **cache driver** for Kirby. This will allow you to use it for caching of
other extensions in Kirby.

**site/config/config.php**

```php
return [
    // ... other options
    
    // example of using mongodb as cache driver for storing uuids
    // instead of the default file-based cache
    'cache' => [
        'uuid' => [
            'type' => 'mongodb',
        ],
    ],
];
```

## Settings

| bnomei.mongodb.          | Default     | Description                                                                   |            
|--------------------------|-------------|-------------------------------------------------------------------------------|
| host                     | `127.0.0.1` |                                                                               |
| port                     | `27017`     |                                                                               |
| username                 | `null`      |                                                                               |
| password                 | `null`      |                                                                               |
| database                 | `kirby`     | you can give it any name you want and MongoDB will create it for you          |
| khulan.read              | `false`     | read from cache is disabled by default as loading from file might be faster   |
| khulan.write             | `true`      | write to cache for all models that use the ModelWithKhulan trait              |
| khulan.patch-files-class | `true`      | monkey-patch the \Kirby\CMS\Files class to use Khulan for caching its content |

## Disclaimer

This plugin is provided "as is" with no guarantee. Use it at your own risk and always test it yourself before using it
in a production environment. If you find any issues,
please [create a new issue](https://github.com/bnomei/kirby-mongodb/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or
any other form of hate speech.


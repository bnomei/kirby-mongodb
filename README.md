# 🐎 Khulan - Kirby MongoDB

![Release](https://flat.badgen.net/packagist/v/bnomei/kirby-mongodb?color=ae81ff)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby-mongodb?color=272822)
[![Build Status](https://flat.badgen.net/travis/bnomei/kirby-mongodb)](https://travis-ci.com/bnomei/kirby-mongodb)
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby-mongodb)](https://codeclimate.com/github/bnomei/kirby-mongodb)
[![Twitter](https://flat.badgen.net/badge/twitter/bnomei?color=66d9ef)](https://twitter.com/bnomei)

Khulan is a cache driver and content cache for Kirby using MongoDB.

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

## Usecase

The plugin caches all content files and keeps the cache up to date when you add/remove or update content. This cache
will be used when constructing page/file/user objects making everything that involves model faster (even the
Panel).

## Setup

For each template you want to be cached you need to use a model to add the content cache logic using a trait.

**site/models/default.php**

```php
class DefaultPage extends \Kirby\Cms\Page
{
    use \Bnomei\Khulan;
}
```

> Note: You can also use the trait for user models. File models are patched automatically.

## Accessing the Cache

```php
/** @var \Kirby\Cache\Cache $cache */
$cache = \Bnomei\Mongodb::singleton();

$cache->set('mykey', 'myvalue', 5); // ttl in minutes
$value = $cache->get('mykey');
```

## Accessing the MongoDB Client

```php
/** @var \MongoDB\Client $client */
$client = \Bnomei\Mongodb::singleton()->client();

$client->listDatabases();
```

## Settings

| bnomei.mongodb.          | Default     | Description                                                                  |            
|--------------------------|-------------|------------------------------------------------------------------------------|
| host                     | `127.0.0.1` |                                                                              |
| port                     | `27017`     |                                                                              |
| khulan.read              | `true`      | read from cache                                                              |
| khulan.write             | `true`      | write to cache                                                               |
| khulan.patch-files-class | `true`      | monkey-patch the \Kirby\CMS\Files class to use Khulan for caching it content |

## Disclaimer

This plugin is provided "as is" with no guarantee. Use it at your own risk and always test it yourself before using it
in a production environment. If you find any issues,
please [create a new issue](https://github.com/bnomei/kirby-mongodb/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or
any other form of hate speech.


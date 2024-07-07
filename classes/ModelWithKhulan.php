<?php

declare(strict_types=1);

namespace Bnomei;

use DateTime;
use Exception;
use Kirby\Cms\Blueprint;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

trait ModelWithKhulan
{
    private bool $khulanCacheWillBeDeleted = false;

    public function hasKhulan(): bool
    {
        if ($this instanceof File) {
            return $this->parent()->hasKhulan() === true;
        }

        return true;
    }

    public function setKhulanCacheWillBeDeleted(bool $value): void
    {
        $this->khulanCacheWillBeDeleted = $value;
    }

    public function keyKhulan(?string $languageCode = null): string
    {
        $key = $this->id(); // can not use UUID since content not loaded yet
        if (! $languageCode) {
            $languageCode = kirby()->languages()->count() ? kirby()->language()->code() : null;
        }
        if ($languageCode) {
            $key = $key.'-'.$languageCode;
        }

        // mongodb _id must be 24 chars long
        return substr(hash('md5', $key), 0, 24);
    }

    public function readContentCache(?string $languageCode = null): ?array
    {
        $document = khulan()->findOne([
            '_id' => $this->keyKhulan($languageCode),
        ]);

        return $document ? iterator_to_array($document) : null;
    }

    public function readContent(?string $languageCode = null): array
    {
        // read from boostedCache if exists
        $data = option('bnomei.mongodb.khulan.read') === false || option('debug') ? null : $this->readContentCache($languageCode);

        $data = $this->decodeKhulan($data);

        // read from file and update boostedCache
        if (! $data) {
            $data = parent::readContent($languageCode);

            if ($data && $this->khulanCacheWillBeDeleted !== true) {
                $this->writeKhulan($data, $languageCode);
            }
        }

        return $data;
    }

    public function writeKhulan(?array $data = null, ?string $languageCode = null): bool
    {
        $cache = Mongodb::singleton();
        if (! $cache || option('bnomei.mongodb.khulan.write') === false) {
            return true;
        }

        $modified = null;
        if ($this instanceof Site) {
            // site()->modified() does crawl index but not return change of its content file
            $siteFile = site()->storage()->read(
                site()->storage()->defaultVersion(),
                kirby()->defaultLanguage()->code()
            )[0];
            $modified = $modified.filemtime($siteFile);
        } else {
            $modified = $this->modified();
        }

        // in rare case file does not exists or is not readable
        if ($modified === false) {
            $this->deleteKhulan(); // whatever was in the cache is no longer valid

            return false; // try again another time
        }

        $modelType = 'page';
        if ($this instanceof Site) {
            $modelType = 'site';
        } elseif ($this instanceof User) {
            $modelType = 'user';
        } elseif ($this instanceof File) {
            $modelType = 'file';
        }

        $meta = [
            'id' => $this->id() ?? null,
            'modified' => $modified,
            'modified{}' => new UTCDateTime($modified * 1000),
            'class' => $this::class,
            'language' => $languageCode,
            'modelType' => $modelType,
        ];
        if ($this instanceof Page) {
            $slug = explode('/', $this->id());
            $meta['num'] = $this->num() ? (int) $this->num() : null;
            $meta['slug'] = $this->id() ? array_pop($slug) : null;
            $meta['template'] = $this->intendedTemplate()->name();
            $meta['status'] = $this->status();
        } elseif ($this instanceof File) {
            // can not use $file->content() since it would trigger a loop
            $meta['sort'] = A::get($data, 'sort') ? (int) A::get($data, 'sort') : null;
            $meta['filename'] = $this->filename();
            $meta['template'] = A::get($data, 'template');
            $meta['mimeType'] = F::mime($this->root());
            $meta['parent{}'] = new ObjectId($this->parent()->keyKhulan($languageCode));
        } elseif ($this instanceof User) {
            $meta['email'] = $this->email();
            $meta['name'] = $this->name()->isNotEmpty() ? $this->name()->value() : null;
            $meta['role'] = $this->role()->name();
        }
        $data = array_merge($this->encodeKhulan($data, $languageCode), $meta);

        // _id is not allowed as data key
        if (array_key_exists('_id', $data)) {
            unset($data['_id']);
        }

        $status = khulan()->findOneAndUpdate(
            ['_id' => new ObjectId($this->keyKhulan($languageCode))],
            ['$set' => $data],
            ['upsert' => true]
        );

        return $status != null;
    }

    public function writeContent(array $data, ?string $languageCode = null): bool
    {
        // write to file and cache
        return parent::writeContent($data, $languageCode) &&
            $this->writeKhulan($data, $languageCode);
    }

    public function deleteKhulan(): bool
    {
        $cache = Mongodb::singleton();
        if (! $cache) {
            return true;
        }

        $this->setKhulanCacheWillBeDeleted(true);

        // using many and by id to delete all language versions
        // as well as the version without a language code
        khulan()->deleteMany([
            'id' => $this->id(),
        ]);

        return true;
    }

    public function delete(bool $force = false): bool
    {
        $cache = Mongodb::singleton();
        if (! $cache) {
            return parent::delete($force);
        }

        $success = parent::delete($force);
        $this->deleteKhulan();

        return $success;
    }

    public function encodeKhulan(array $data, ?string $languageCode = null): array
    {
        $blueprint = null;
        if ($this instanceof Page) {
            $blueprint = $this->blueprint()->fields();
        } elseif ($this instanceof File) {
            // $blueprint = $this->blueprint();
            // does not work as that would trigger a loop reading the content
            // but it can be read manually
            $blueprint = Blueprint::find('files/'.A::get($data, 'template', 'default'));
            $blueprint = A::get($blueprint, 'fields');
        }
        if (! $blueprint) {
            return $data;
        }
        // foreach each key value pairs
        $copy = $data;
        foreach ($data as $key => $value) {
            $field = A::get($blueprint, $key);
            if (! $field) {
                continue;
            }
            if (is_string($value)) {
                $type = A::get($field, 'type');

                // if it is a comma separated list unroll it to an array. validate if it is with a regex but allow for spaces chars after the comma

                if (preg_match('/^[\w\s-]+(,\s*[\w\s-]+)*$/', $value) && in_array(
                    $type, ['tags', 'select', 'multiselect', 'radio', 'checkbox']
                )) {
                    $tags = explode(',', $value);
                    $tags = array_map('trim', $tags);
                    $tags = array_filter($tags, function ($value) {
                        return ! empty($value);
                    });
                    $copy[$key.'[,]'] = $tags;

                    continue; // process next key value pair
                }

                if (in_array(
                    $type, ['date']
                )) {
                    // convert iso date to mongodb date
                    $copy[$key.'{}'] = new UTCDateTime((new DateTime($value))->getTimestamp() * 1000);
                }

                // if it is a valid yaml string, convert it to an array
                if (in_array(
                    $type, ['pages', 'files', 'users']
                )) {
                    try {
                        $v = Yaml::decode($value);
                        if (is_array($v)) {
                            $copy[$key.'[]'] = $v;
                            $copy[$key.'{}'] = [];
                            // resolve each and set objectid
                            foreach ($v as $vv) {
                                $modelType = null;
                                if (Str::startsWith($vv, 'page://')) {
                                    $modelType = 'page';
                                } elseif (Str::startsWith($vv, 'file://')) {
                                    $modelType = 'file';
                                } elseif (Str::startsWith($vv, 'user://')) {
                                    $modelType = 'user';
                                } elseif (Str::startsWith($vv, 'site://')) {
                                    $modelType = 'site';
                                }
                                if (! $modelType) {
                                    continue;
                                }
                                $vv = str_replace($modelType.'://', '', $vv);
                                $query = [
                                    '$or' => [
                                        ['id' => $vv],
                                        ['uuid' => $vv],
                                    ],
                                ];
                                if (kirby()->multilang() && $languageCode) {
                                    $query = [
                                        '$and' => [
                                            $query,
                                            ['language' => $languageCode],
                                        ],
                                    ];
                                }
                                $document = khulan()->findOne($query);
                                if ($document) {
                                    $copy[$key.'{}'][] = $document['_id'];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // do nothing
                        // ray($e->getMessage());
                    }
                }
                // if it is a valid yaml string, convert it to an array
                if (in_array(
                    $type, ['object', 'structure']
                )) {
                    try {
                        $v = Yaml::decode($value);
                        if (is_array($v)) {
                            $copy[$key.'[]'] = $v;
                        }
                    } catch (Exception $e) {
                        // do nothing
                    }
                }
            }
        }

        return $copy;
    }

    public function decodeKhulan(?array $data = []): array
    {
        if (empty($data)) {
            return [];
        }

        // flatten to array
        $data = iterator_to_array($data);
        // and remove any mongodb objects
        $data = json_decode(json_encode($data), true);

        $copy = $data;

        // remove any empty values
        $copy = array_filter($copy, function ($value) {
            return ! empty($value);
        });

        // remove meta
        $meta = [
            'id',
            'modified',
            'modified{}',
            'class',
            'language',
            'modelType',
        ];
        if ($this instanceof Page) {
            $meta[] = 'num';
            $meta[] = 'slug';
            $meta[] = 'status';
            $meta[] = 'template';
        } elseif ($this instanceof File) {
            $meta[] = 'sort';
            $meta[] = 'filename';
            $meta[] = 'mimeType';
            $meta[] = 'template';
        } elseif ($this instanceof User) {
            $meta[] = 'email';
            $meta[] = 'name';
            $meta[] = 'role';
        }
        foreach ($meta as $key) {
            if (array_key_exists($key, $copy)) {
                unset($copy[$key]);
            }
        }

        // remove dynamic keys
        foreach ($data as $key => $value) {
            if (is_array($value) && (
                Str::endsWith($key, '[,]') ||
                Str::endsWith($key, '[]') ||
                Str::endsWith($key, '{}')
            )
            ) {
                unset($copy[$key]);
            }
        }

        return $copy;
    }
}

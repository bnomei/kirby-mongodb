<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Data\Yaml;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

trait ModelWithKhulan
{
    private bool $khulanCacheWillBeDeleted = false;

    public function hasKhulan(): bool
    {
        return true;
    }

    public function setBoostWillBeDeleted(bool $value): void
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

        return hash('xxh3', $key);
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

        $modified = $this->modified();

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

        $slug = explode('/', $this->id());
        $meta = [
            'id' => $this->id() ?? null,
            'modified' => $modified,
            'slug' => $this->id() ? array_pop($slug) : null,
            'template' => $this->intendedTemplate()->name(),
            'class' => $this::class,
            'language' => $languageCode,
            'modelType' => $modelType,
        ];
        $data = $this->encodeKhulan($data) + $meta;

        // _id is not allowed as data key
        if (array_key_exists('_id', $data)) {
            unset($data['_id']);
        }

        ray('write', $data)->red();

        $status = khulan()->findOneAndUpdate(
            ['_id' => $this->keyKhulan($languageCode)],
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

        $this->setBoostWillBeDeleted(true);

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

    public function encodeKhulan(array $data): array
    {
        // foreach each key value pairs
        $copy = $data;
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $type = A::get($this->blueprint()->field($key), 'type');

                // if it is a comma separated list unroll it to an array. validate if it is with a regex but allow for spaces chars after the comma

                if (preg_match('/^[\w-]+(,\s*[\w-]+)*$/', $value) && in_array(
                    $type, ['tags', 'select', 'multiselect', 'radio', 'checkbox']
                )) {
                    $copy[$key.'[,]'] = explode(',', $value);

                    continue; // process next key value pair
                }

                // if it is a valid yaml string, convert it to an array
                if (in_array(
                    $type, ['pages', 'files', 'users', 'object', 'structure']
                )) {
                    try {
                        $v = Yaml::decode($value);
                        if (is_array($v)) {
                            $copy[$key.'[]'] = $v;
                        }
                    } catch (\Exception $e) {
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
        foreach ($data as $key => $value) {
            if (is_array($value) && Str::endsWith($key, '[,]')) {
                unset($copy[$key]);
            } elseif (is_array($value) && Str::endsWith($key, '[]')) {
                unset($copy[$key]);
            }
        }
        $meta = [
            'id',
            'modified',
            'slug',
            'template',
            'class',
            'language',
            'modelType',
        ];
        foreach ($meta as $key) {
            if (array_key_exists($key, $copy)) {
                unset($copy[$key]);
            }
        }

        return $copy;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;

trait Khulan
{
    /** @var bool */
    private bool $khulanCacheWillBeDeleted;

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
        $key = hash('xxh3', $this->id()); // can not use UUID since content not loaded yet
        if (! $languageCode) {
            $languageCode = kirby()->languages()->count() ? kirby()->language()->code() : null;
        }
        if ($languageCode) {
            $key = $key.'-'.$languageCode;
        }

        return $key;
    }

    public function readContentCache(?string $languageCode = null): ?array
    {
        // TODO: change to direct client findByID
        return Mongodb::singleton()->get(
            $this->keyKhulan($languageCode).'-content',
            null
        );
    }

    public function readContent(?string $languageCode = null): array
    {
        // read from boostedCache if exists
        $data = option('bnomei.mongodb.khulan.read') === false || option('debug') ? null : $this->readContentCache($languageCode);

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

        // TODO: change to direct client insertOne
        return $cache->set(
            $this->keyKhulan($languageCode).'-content',
            array_filter($data, fn ($content) => $content !== null),
            option('bnomei.mongodb.expire')
        );
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

        foreach (kirby()->languages() as $language) {
            // TODO: change to direct client deleteByID
            $cache->remove(
                $this->keyKhulan($language->code()).'-content'
            );
        }

        // TODO: change to direct client deleteByID
        $cache->remove(
            $this->keyKhulan().'-content'
        );

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
}

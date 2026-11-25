<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;

trait ModelWithKhulan
{
    public function hasKhulan(): bool
    {
        return ! $this instanceof File || KhulanStorage::supports($this->parent());
    }

    public function keyKhulan(?string $languageCode = null): string
    {
        return (new KhulanDocument($this))->keyKhulan($languageCode);
    }

    public function readContentCache(?string $languageCode = null): ?array
    {
        return (new KhulanDocument($this))->readContentCache($languageCode);
    }

    public function writeKhulan(?array $data = null, ?string $languageCode = null, ?int $modified = null, ?string $uuid = null): bool
    {
        return (new KhulanDocument($this))->writeKhulan($data, $languageCode, $modified, $uuid);
    }

    public function deleteKhulan(): bool
    {
        return (new KhulanDocument($this))->deleteKhulan();
    }

    public function encodeKhulan(?array $data = null, ?string $languageCode = null, array &$references = []): array
    {
        return (new KhulanDocument($this))->encodeKhulan($data, $languageCode, $references);
    }

    public function decodeKhulan(?array $data = []): array
    {
        return (new KhulanDocument($this))->decodeKhulan($data);
    }
}

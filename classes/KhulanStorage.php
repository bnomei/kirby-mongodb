<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Cms\Language;
use Kirby\Cms\Languages;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\VersionId;
use MongoDB\BSON\ObjectId;

final class KhulanStorage extends PlainTextStorage
{
    private readonly KhulanDocument $document;

    public function __construct(Page|File|Site|User $model)
    {
        parent::__construct($model);
        $this->document = new KhulanDocument($model);
    }

    /** @phpstan-assert-if-true =Page|File|Site|User $model */
    public static function supports(ModelWithContent $model): bool
    {
        if ($model instanceof File) {
            return option('bnomei.mongodb.khulan.patch-files-class') && self::supports($model->parent());
        }

        return ($model instanceof Page || $model instanceof Site || $model instanceof User)
            && method_exists($model, 'hasKhulan') && $model->hasKhulan() === true;
    }

    public function read(VersionId $versionId, Language $language): array
    {
        if ($versionId->is('latest') && option('bnomei.mongodb.khulan.read') && ! option('debug')) {
            $doc = $this->document->readContentCache($language->isSingle() ? '' : $language->code());
            // Legacy projections are lossy: use the authoritative file until reindexed.
            if (isset($doc['_content'])) {
                return (array) $doc['_content'];
            }
        }

        return parent::read($versionId, $language);
    }

    protected function write(VersionId $versionId, Language $language, array $fields): void
    {
        parent::write($versionId, $language, $fields);
        if ($versionId->is('latest')) {
            $this->indexLanguage($language);
        }
    }

    public function delete(VersionId $versionId, Language $language): void
    {
        parent::delete($versionId, $language);
        if ($versionId->is('latest') && option('bnomei.mongodb.khulan.write')) {
            $key = $this->document->keyKhulan($language->isSingle() ? '' : $language->code());
            Khulan::removeDocuments(['_id' => new ObjectId($key)]);
        }
    }

    public function indexLanguage(Language $language): void
    {
        if (! option('bnomei.mongodb.khulan.write')) {
            return;
        }

        $latest = VersionId::latest();
        $modified = parent::modified($latest, $language);
        if ($modified !== null) {
            $fields = parent::read($latest, $language);
            // UUIDs belong to the model, not to the translation's stored fields.
            $uuid = $language->isDefault() ? null : (parent::read($latest, Language::ensure('default'))['uuid'] ?? null);
            $this->document->writeKhulan($fields, $language->isSingle() ? '' : $language->code(), $modified, $uuid);
        }
    }

    public function indexLanguages(): void
    {
        foreach (Languages::ensure() as $language) {
            $this->indexLanguage($language);
        }
    }
}

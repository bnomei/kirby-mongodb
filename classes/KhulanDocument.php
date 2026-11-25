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

final readonly class KhulanDocument
{
    public function __construct(private Page|File|Site|User $model) {}

    public function keyKhulan(?string $languageCode = null): string
    {
        $languageCode ??= kirby()->language()?->code() ?? '';
        // IDs can overlap between e.g. a page and a user. Avoid reading content to construct the key.
        $model = $this->model;
        $type = $model instanceof File ? 'file:'.$model->parent()::CLASS_ALIAS : $model::CLASS_ALIAS;
        $key = $type."\0".$model->id()."\0".$languageCode;

        // mongodb _id must be 24 chars long
        return substr(hash('md5', $key), 0, 24);
    }

    public function readContentCache(?string $languageCode = null): ?array
    {
        $document = Mongodb::singleton()->contentCollection()->findOne([
            '_id' => new ObjectId($this->keyKhulan($languageCode)),
        ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        return is_array($document) ? $document : null;
    }

    public function writeKhulan(?array $data = null, ?string $languageCode = null, ?int $modified = null, ?string $uuid = null): bool
    {
        if (option('bnomei.mongodb.khulan.write') === false) {
            return true;
        }

        $model = $this->model;
        $modified ??= $model->modified();
        if (! is_int($modified)) {
            return false;
        }

        $data ??= [];
        $meta = [
            'id' => $model->id(),
            '_content' => $data,
            'modified' => $modified,
            'modified{}' => new UTCDateTime($modified * 1000),
            'class' => $model::class,
            'language' => $languageCode ?: null,
            'modelType' => $model::CLASS_ALIAS,
        ];
        if ($uuid !== null) {
            $meta['uuid'] = $uuid;
        }
        if ($model instanceof Page) {
            $meta['num'] = $model->num() ? (int) $model->num() : null;
            $meta['slug'] = $model->uid();
            $meta['template'] = $model->intendedTemplate()->name();
            $meta['status'] = $model->status();
        } elseif ($model instanceof File) {
            // can not use $file->content() since it would trigger a loop
            $meta['sort'] = A::get($data, 'sort') ? (int) A::get($data, 'sort') : null;
            $meta['filename'] = $model->filename();
            $meta['template'] = A::get($data, 'template');
            $meta['mimeType'] = $model->root() ? F::mime($model->root()) : null;
            $parent = $model->parent();
            $meta['parentType'] = $parent::CLASS_ALIAS;
            $meta['parent{}'] = new ObjectId((new self($parent))->keyKhulan($languageCode));
        } elseif ($model instanceof User) {
            $meta['email'] = $model->email();
            $meta['name'] = $model->name()->isNotEmpty() ? $model->name()->value() : null;
            $meta['role'] = $model->role()->name();
        }
        $references = [];
        $data = array_merge($this->encodeKhulan($data, $languageCode, $references), $meta, ['_references' => $references]);

        // _id is not allowed as data key
        if (array_key_exists('_id', $data)) {
            unset($data['_id']);
        }

        $status = Mongodb::singleton()->contentCollection()->replaceOne(
            ['_id' => new ObjectId($this->keyKhulan($languageCode))],
            $data,
            ['upsert' => true]
        );

        if ($status->isAcknowledged() && $status->getUpsertedCount() > 0) {
            Khulan::refreshReferences($data);
        }

        return $status->isAcknowledged();
    }

    public function deleteKhulan(): bool
    {
        if (option('bnomei.mongodb.khulan.write') === false) {
            return true;
        }

        // using many and by id to delete all language versions
        // as well as the version without a language code
        Khulan::removeDocuments([
            'id' => $this->model->id(),
            'modelType' => $this->model::CLASS_ALIAS,
            ...($this->model instanceof File ? ['parentType' => $this->model->parent()::CLASS_ALIAS] : []),
        ]);

        return true;
    }

    public function encodeKhulan(?array $data = null, ?string $languageCode = null, array &$references = []): array
    {
        if (! $data) {
            return [];
        }

        $blueprint = null;
        if ($this->model instanceof Page) {
            $blueprint = $this->model->blueprint()->fields();
        } elseif ($this->model instanceof File) {
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
                    $type, ['pages', 'files', 'users', 'pagepicker', 'filepicker', 'userpicker']
                )) {
                    try {
                        $v = Yaml::decode($value);
                        $copy[$key.'[]'] = $v;
                        $copy[$key.'{}'] = [];
                        // resolve each and set objectid
                        foreach ($v as $vv) {
                            if (! is_string($vv)) {
                                continue;
                            }
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
                            $references[] = $vv;
                            $vv = str_replace($modelType.'://', '', $vv);
                            $query = [
                                'modelType' => $modelType,
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
                            $document = Mongodb::singleton()->contentCollection()->findOne($query, ['projection' => ['_id' => 1], 'typeMap' => ['root' => 'array']]);
                            if (is_array($document)) {
                                $copy[$key.'{}'][] = $document['_id'];
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
                        $copy[$key.'[]'] = Yaml::decode($value);
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
        if (isset($data['_content']) && is_array($data['_content'])) {
            return $data['_content'];
        }

        // flatten to array
        $data = iterator_to_array($data);
        // and remove any mongodb objects
        $json_encode = json_encode($data);
        if (! $json_encode) {
            throw new Exception('Could not encode data to JSON.');
        }
        $data = json_decode($json_encode, true);
        if (! is_array($data)) {
            throw new Exception('Could not decode JSON to array.');
        }

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
        if ($this->model instanceof Page) {
            $meta[] = 'num';
            $meta[] = 'slug';
            $meta[] = 'status';
            $meta[] = 'template';
        } elseif ($this->model instanceof File) {
            $meta[] = 'sort';
            $meta[] = 'filename';
            $meta[] = 'mimeType';
            $meta[] = 'template';
        } elseif ($this->model instanceof User) {
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

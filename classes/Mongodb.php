<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\Cache;
use Kirby\Cache\Value;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;

use function option;

final class Mongodb extends Cache
{
    protected ?Client $_client = null;

    /**
     * Sets all parameters which are needed to connect to MongoDB.
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'debug' => option('debug'),
            'host' => option('bnomei.mongodb.host'),
            'port' => option('bnomei.mongodb.port'),
            'database' => option('bnomei.mongodb.database'),
            'username' => option('bnomei.mongodb.username'),
            'password' => option('bnomei.mongodb.password'),
            'collection-cache' => option('bnomei.mongodb.collections.cache'),
            'collection-content' => option('bnomei.mongodb.collections.content'),
        ], $options);

        foreach ($this->options as $key => $call) {
            if (! is_string($call) && is_callable($call) && in_array($key, [
                'host', 'port', 'database', 'username', 'password',
            ])) {
                $this->options[$key] = $call();
            }
        }

        parent::__construct($this->options);

        // client init is done lazily see ->client()
    }

    /**
     * @return array
     */
    public function option(?string $key = null)
    {
        if ($key) {
            return A::get($this->options, $key);
        }

        return $this->options;
    }

    public function key(string $key): string
    {
        $key = parent::key($key);

        return hash('xxh3', $key);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, $value, int $minutes = 0): bool
    {
        /* SHOULD SET EVEN IN DEBUG
        if ($this->option('debug')) {
            return true;
        }
        */

        $document = $this->cacheCollection()->findOneAndUpdate(
            ['_id' => $this->key($key)],
            ['$set' => (new Value($value, $minutes))->toArray() + [
                'expires_at' => $minutes ? time() + $minutes * 60 : null,
            ]],
            ['upsert' => true]
        );

        return $document !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function retrieve(string $key): ?Value
    {
        $value = $value ?? $this->cacheCollection()->findOne([
            '_id' => $this->key($key),
        ]);

        if (! $value) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0];
        }

        if ($value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        }

        $value = is_array($value) ? Value::fromArray($value) : $value;

        return $value;
    }

    public function get(string $key, $default = null)
    {
        if ($this->option('debug')) {
            return $default;
        }

        return parent::get($key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): bool
    {
        return $this->cacheCollection()->deleteOne([
            '_id' => $this->key($key),
        ])->isAcknowledged();
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        return $this->cacheCollection()->deleteMany([])->isAcknowledged();
    }

    public function benchmark(int $count = 10)
    {
        $prefix = 'mongodb-benchmark-';
        $mongodb = $this;
        $file = kirby()->cache('bnomei.mongodb'); // neat, right? ;-)

        foreach (['mongodb' => $mongodb, 'file' => $file] as $label => $driver) {
            $time = microtime(true);
            for ($i = 0; $i < $count; $i++) {
                $key = $prefix.$i;
                if (! $driver->get($key)) {
                    $driver->set($key, Str::random(1000));
                }
            }
            for ($i = $count * 0.6; $i < $count * 0.8; $i++) {
                $key = $prefix.$i;
                $driver->remove($key);
            }
            for ($i = $count * 0.8; $i < $count; $i++) {
                $key = $prefix.$i;
                $driver->set($key, Str::random(1000));
            }
            echo $label.' : '.(microtime(true) - $time).PHP_EOL;

            // cleanup
            for ($i = 0; $i < $count; $i++) {
                $key = $prefix.$i;
                $driver->remove($key);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function root(): string
    {
        return kirby()->cache('bnomei.mongodb')->root();
    }

    public function cache(): self
    {
        return $this;
    }

    public function client(): Client
    {
        if (! $this->_client) {
            if (! empty($this->options['username']) && ! empty($this->options['password'])) {
                $auth = $this->options['username'].':'.$this->options['password'].'@';
            } else {
                $auth = '';
            }

            $this->_client = new Client(
                'mongodb://'.$auth.$this->options['host'].':'.$this->options['port']
            );
            $this->_client->selectDatabase($this->options['database']);

            if ($this->option('debug')) {
                $this->flush();
            }
        }

        return $this->_client;
    }

    public function collection(string $collection): Collection
    {
        return $this->client()->selectCollection($this->options['database'], $collection);
    }

    public function cacheCollection(): Collection
    {
        return $this->collection($this->options['collection-cache']);
    }

    public function contentCollection(): Collection
    {
        return $this->collection($this->options['collection-content']);
    }

    public static $singleton;

    public static function singleton(array $options = []): self
    {
        if (! self::$singleton) {
            self::$singleton = new self($options);
        }

        return self::$singleton;
    }
}

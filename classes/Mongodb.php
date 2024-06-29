<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\Cache;
use Kirby\Cache\Value;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use MongoDB\Client;

final class Mongodb extends Cache
{
    private $shutdownCallbacks = [];

    /**
     * store for the connection
     * @var Client;
     */
    protected $_client;

    /**
     * Sets all parameters which are needed to connect to Redis
     */
    public function __construct(array $options = [], array $optionsClient = [])
    {
        $this->options = array_merge([
            'debug' => \option('debug'),
            'host' => \option('bnomei.mongodb.host'),
            'port' => \option('bnomei.mongodb.port'),
        ], $options);

        foreach ($this->options as $key => $call) {
            if (!is_string($call) && is_callable($call) && in_array($key, [
                    'host', 'port', 'database', 'password',
                ])) {
                $this->options[$key] = $call();
            }
        }

        parent::__construct($this->options);

        $this->_client = new Client(
            'mongodb://' . $this->options['host'] . ':' . $this->options['port']
        );

        if ($this->option('debug')) {
            $this->flush();
        }
    }

    public function register_shutdown_function($callback)
    {
        $this->shutdownCallbacks[] = $callback;
    }

    public function __destruct()
    {
        foreach ($this->shutdownCallbacks as $callback) {
            if (!is_string($callback) && is_callable($callback)) {
                $callback();
            }
        }

        if ($this->option('debug')) {
            return;
        }
    }

    public function client(): Client
    {
        return $this->_client;
    }

    /**
     * @param string|null $key
     * @return array
     */
    public function option(?string $key = null)
    {
        if ($key) {
            return A::get($this->options, $key);
        }
        return $this->options;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value, int $minutes = 0): bool
    {
        /* SHOULD SET EVEN IN DEBUG
        if ($this->option('debug')) {
            return true;
        }
        */

        $key = $this->key($key);
        $value = (new Value($value, $minutes))->toJson();

        $status = $method->set(
            $key,
            $value
        );

        if ($minutes) {
            $status = $method->expireat(
                $key,
                $this->expiration($minutes)
            );
        }


        return $status == 'OK' || $status == 'QUEUED';
    }

    /**
     * @inheritDoc
     */
    public function retrieve(string $key): ?Value
    {
        $key = $this->key($key);

        $value = $value ?? $this->_client->get($key);

        if ($value instanceof Status && $value->getPayload() === 'QUEUED') {
            $value = null;
        }
        $value = is_string($value) ? Value::fromJson($value) : $value;

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
     * @inheritDoc
     */
    public function remove(string $key): bool
    {
        $key = $this->key($key);

        $status = $this->_client->del($key);
        if (is_int($status)) {
            return $status > 0;
        }
        if (is_string($status)) {
            return $status === 'QUEUED';
        }
        return false;
    }

    public function key(string $key): string
    {
        $key = parent::key($key);
        return $this->option('key')($key);
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        $prefix = $this->key('');
        $keys = $this->_client->keys($prefix . '*');
        $this->_client->del($keys);

        return true;
    }

    public function flushdb(): bool
    {
        return $this->_client->flushdb() == 'OK';
    }

    public function benchmark(int $count = 10)
    {
        $prefix = "mongodb-benchmark-";
        $mongodb = $this;
        $file = kirby()->cache('bnomei.mongodb'); // neat, right? ;-)

        foreach (['mongodb' => $mongodb, 'file' => $file] as $label => $driver) {
            $time = microtime(true);
            for ($i = 0; $i < $count; $i++) {
                $key = $prefix . $i;
                if (!$driver->get($key)) {
                    $driver->set($key, Str::random(1000));
                }
            }
            for ($i = $count * 0.6; $i < $count * 0.8; $i++) {
                $key = $prefix . $i;
                $driver->remove($key);
            }
            for ($i = $count * 0.8; $i < $count; $i++) {
                $key = $prefix . $i;
                $driver->set($key, Str::random(1000));
            }
            echo $label . ' : ' . (microtime(true) - $time) . PHP_EOL;

            // cleanup
            for ($i = 0; $i < $count; $i++) {
                $key = $prefix . $i;
                $driver->remove($key);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function root(): string
    {
        return kirby()->cache('bnomei.mongodb')->root();
    }

    private static $singleton;

    public static function singleton(array $options = [], array $optionsClient = []): self
    {
        if (!static::$singleton) {
            static::$singleton = new self($options, $optionsClient);
        }
        return static::$singleton;
    }
}

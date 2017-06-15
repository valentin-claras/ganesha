<?php
namespace Ackintosh\Ganesha\Strategy;

use Ackintosh\Ganesha;
use Ackintosh\Ganesha\Configuration;
use Ackintosh\Ganesha\Storage;
use Ackintosh\Ganesha\StrategyInterface;
use Ackintosh\Ganesha\Exception\StorageException;

class Count implements StrategyInterface
{
    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var \Ackintosh\Ganesha\Storage
     */
    private $storage;

    /**
     * @var array
     */
    private static $requirements = [
        'adapter',
        'failureThreshold',
        'intervalToHalfOpen',
    ];

    /**
     * @param Configuration $configuration
     */
    private function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @param array $params
     * @throws \LogicException
     */
    public static function validate($params)
    {
        foreach (self::$requirements as $r) {
            if (!isset($params[$r])) {
                throw new \LogicException($r . ' is required');
            }
        }
    }

    /**
     * @param Configuration $configuration
     * @return Count
     */
    public static function create(Configuration $configuration)
    {
        $strategy = new self($configuration);
        $strategy->setStorage(
            new Storage(
                $configuration['adapter'],
                null
            )
        );

        return $strategy;
    }

    /**
     * @param \Ackintosh\Ganesha\Storage $storage
     */
    public function setStorage($storage)
    {
        $this->storage = $storage;
    }

    /**
     * @return int
     */
    public function recordFailure($resource)
    {
        $this->storage->setLastFailureTime($resource, time());
        $this->storage->incrementFailureCount($resource);

        if ($this->storage->getFailureCount($resource) >= $this->configuration['failureThreshold']
            && $this->storage->getStatus($resource) === Ganesha::STATUS_CALMED_DOWN
        ) {
            $this->storage->setStatus($resource, Ganesha::STATUS_TRIPPED);
            return Ganesha::STATUS_TRIPPED;
        }

        return Ganesha::STATUS_CALMED_DOWN;
    }

    /**
     * @return void
     */
    public function recordSuccess($resource)
    {
        $this->storage->decrementFailureCount($resource);

        if ($this->storage->getFailureCount($resource) === 0
            && $this->storage->getStatus($resource) === Ganesha::STATUS_TRIPPED
        ) {
            $this->storage->setStatus($resource, Ganesha::STATUS_CALMED_DOWN);
        }
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->storage->reset();
    }

    /**
     * @return bool
     */
    public function isAvailable($resource)
    {
        try {
            return $this->isClosed($resource) || $this->isHalfOpen($resource);
        } catch (StorageException $e) {
            throw $e;
        }
    }

    /**
     * @return bool
     * @throws StorageException
     */
    private function isClosed($resource)
    {
        try {
            return $this->storage->getFailureCount($resource) < $this->configuration['failureThreshold'];
        } catch (StorageException $e) {
            throw $e;
        }
    }

    /**
     * @return bool
     * @throws StorageException
     */
    private function isHalfOpen($resource)
    {
        if (is_null($lastFailureTime = $this->storage->getLastFailureTime($resource))) {
            return false;
        }

        if ((time() - $lastFailureTime) > $this->configuration['intervalToHalfOpen']) {
            $this->storage->setFailureCount($resource, $this->configuration['failureThreshold']);
            $this->storage->setLastFailureTime($resource, time());
            return true;
        }

        return false;
    }
}

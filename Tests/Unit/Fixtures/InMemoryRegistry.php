<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use TYPO3\CMS\Core\Registry;

/**
 * The Registry without a database. `failing` makes every access throw.
 */
final class InMemoryRegistry extends Registry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $values = [];
    public bool $failing = false;

    /**
     * The parent's constructor dependencies (deserializer) differ between TYPO3 versions and are not needed here.
     */
    public function __construct() {}

    public function get($namespace, $key, $defaultValue = null)
    {
        if ($this->failing) {
            throw new \RuntimeException('Registry unavailable', 1794000001);
        }
        return $this->values[$namespace][$key] ?? $defaultValue;
    }

    public function set($namespace, $key, $value): void
    {
        if ($this->failing) {
            throw new \RuntimeException('Registry unavailable', 1794000002);
        }
        $this->values[$namespace][$key] = $value;
    }
}

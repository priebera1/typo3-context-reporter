<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * @internal
 */
final readonly class ExtensionInfo
{
    public const EXTENSION_KEY = 'context_reporter';
    public const PACKAGE_NAME = 'priebera/typo3-context-reporter';
    public const PRODUCT_NAME = 'TYPO3 Context Reporter';

    private string $version;

    public function __construct(?string $version = null)
    {
        $this->version = $version ?? self::detectVersion();
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    private static function detectVersion(): string
    {
        try {
            return ExtensionManagementUtility::getExtensionVersion(self::EXTENSION_KEY);
        } catch (\Throwable) {
            return '';
        }
    }
}

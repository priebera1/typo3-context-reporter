<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Email;

use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Loads the plain text email body template. Only .txt files below
 * Resources/Private/ of an extension are allowed: the template is sent by
 * email, so it must never be possible to point it to files such as .env or
 * config/system/settings.php. Falls back to the shipped template, then to a
 * built-in one.
 *
 * @internal
 */
class EmailTemplateLoader
{
    public const FALLBACK_TEMPLATE = "{report.title}\n\n{report.description}\n\n{context.summary}\n\n{context.details}\n\n{report.url}\n";
    private const MAX_TEMPLATE_BYTES = 65536;
    private const ALLOWED_PATH = '~^EXT:[a-z0-9_]+/Resources/Private/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.txt$~D';

    public static function isAllowedPath(string $path): bool
    {
        return preg_match(self::ALLOWED_PATH, $path) === 1 && !str_contains($path, '..');
    }

    public function load(string $path): string
    {
        $template = $this->read($path);
        if ($template === null && $path !== ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE) {
            $template = $this->read(ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE);
        }
        return $template ?? self::FALLBACK_TEMPLATE;
    }

    private function read(string $path): ?string
    {
        if (!self::isAllowedPath($path)) {
            return null;
        }
        $absolutePath = GeneralUtility::getFileAbsFileName($path);
        if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath) || filesize($absolutePath) > self::MAX_TEMPLATE_BYTES) {
            return null;
        }
        $content = file_get_contents($absolutePath);
        return is_string($content) && trim($content) !== '' ? $content : null;
    }
}

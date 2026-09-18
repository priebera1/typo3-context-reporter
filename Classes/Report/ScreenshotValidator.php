<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * Accepts PNG and JPEG only. The format is determined from the file content,
 * never from the client supplied file name or media type. The image is not
 * decoded; only its header is inspected.
 *
 * @internal
 */
final class ScreenshotValidator
{
    public const MAX_EDGE = 10000;
    public const MAX_PIXELS = 60_000_000;

    private const FORMATS = [
        'image/png' => ['signature' => "\x89PNG\r\n\x1a\n", 'extension' => 'png', 'imageType' => IMAGETYPE_PNG],
        'image/jpeg' => ['signature' => "\xFF\xD8\xFF", 'extension' => 'jpg', 'imageType' => IMAGETYPE_JPEG],
    ];

    /**
     * @throws InvalidScreenshotException
     */
    public function validate(string $content, int $maxBytes): Screenshot
    {
        $size = strlen($content);
        if ($size === 0) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::EMPTY, 'The screenshot is empty.');
        }
        if ($size > $maxBytes) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::TOO_LARGE, 'The screenshot exceeds the configured size limit.');
        }

        $mediaType = null;
        foreach (self::FORMATS as $candidate => $format) {
            if (str_starts_with($content, $format['signature'])) {
                $mediaType = $candidate;
                break;
            }
        }
        if ($mediaType === null) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::FORMAT, 'Only PNG and JPEG screenshots are accepted.');
        }

        $info = @getimagesizefromstring($content);
        if (!is_array($info) || $info[2] !== self::FORMATS[$mediaType]['imageType']) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::FORMAT, 'The screenshot is not a valid image.');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::DIMENSIONS, 'The screenshot dimensions are not supported.');
        }

        return new Screenshot(
            content: $content,
            mediaType: $mediaType,
            fileExtension: self::FORMATS[$mediaType]['extension'],
            width: $width,
            height: $height,
            size: $size,
            sha256: hash('sha256', $content),
        );
    }
}

<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Report\InvalidScreenshotException;
use Priebera\ContextReporter\Report\ScreenshotValidator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ScreenshotValidatorTest extends UnitTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/Images/';

    #[Test]
    public function pngIsAcceptedWithDetectedMetadata(): void
    {
        $binary = (string)file_get_contents(self::FIXTURES . 'screenshot.png');

        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);

        self::assertSame('image/png', $screenshot->mediaType);
        self::assertSame('png', $screenshot->fileExtension);
        self::assertSame(3, $screenshot->width);
        self::assertSame(2, $screenshot->height);
        self::assertSame(strlen($binary), $screenshot->size);
        self::assertSame(hash('sha256', $binary), $screenshot->sha256);
        self::assertSame($binary, $screenshot->content);
    }

    #[Test]
    public function jpegIsAccepted(): void
    {
        $binary = (string)file_get_contents(self::FIXTURES . 'screenshot.jpg');

        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);

        self::assertSame('image/jpeg', $screenshot->mediaType);
        self::assertSame('jpg', $screenshot->fileExtension);
    }

    public static function rejectedFileProvider(): \Generator
    {
        yield 'svg can carry script' => ['image.svg'];
        yield 'gif is not a supported screenshot format' => ['screenshot.gif'];
    }

    #[Test]
    #[DataProvider('rejectedFileProvider')]
    public function unsupportedFormatsAreRejected(string $fixture): void
    {
        $this->expectException(InvalidScreenshotException::class);
        (new ScreenshotValidator())->validate((string)file_get_contents(self::FIXTURES . $fixture), 1024 * 1024);
    }

    #[Test]
    public function htmlPretendingToBeAnImageIsRejected(): void
    {
        $this->expectException(InvalidScreenshotException::class);
        (new ScreenshotValidator())->validate('<html><script>alert(1)</script></html>', 1024);
    }

    #[Test]
    public function pngSignatureWithBrokenHeaderIsRejected(): void
    {
        $this->expectException(InvalidScreenshotException::class);
        (new ScreenshotValidator())->validate("\x89PNG\r\n\x1a\n" . 'garbage-without-ihdr', 1024);
    }

    #[Test]
    public function emptyInputIsRejected(): void
    {
        $this->expectException(InvalidScreenshotException::class);
        (new ScreenshotValidator())->validate('', 1024);
    }

    #[Test]
    public function tooLargeInputIsRejectedBeforeInspection(): void
    {
        $binary = (string)file_get_contents(self::FIXTURES . 'screenshot.png');

        try {
            (new ScreenshotValidator())->validate($binary, strlen($binary) - 1);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException $exception) {
            self::assertSame(InvalidScreenshotException::TOO_LARGE, $exception->getReason());
        }
    }

    #[Test]
    public function absurdDimensionsAreRejected(): void
    {
        // A PNG header announcing 30000 x 30000 pixels; the image data itself is never decoded.
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 30000) . pack('N', 30000) . "\x08\x06\x00\x00\x00" . pack('N', 0);

        try {
            (new ScreenshotValidator())->validate($header, 1024 * 1024);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException $exception) {
            self::assertSame(InvalidScreenshotException::DIMENSIONS, $exception->getReason());
        }
    }
}

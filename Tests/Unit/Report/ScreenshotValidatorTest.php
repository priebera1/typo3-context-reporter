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

    #[Test]
    public function generatedImagesInAllShapesAreAccepted(): void
    {
        $validator = new ScreenshotValidator();
        foreach ([[1, 1, false], [1366, 3, false], [7, 5, true], [1, 9, true], [33, 17, true]] as [$width, $height, $interlaced]) {
            $screenshot = $validator->validate(self::png($width, $height, interlaced: $interlaced), 1024 * 1024);
            self::assertSame([$width, $height], [$screenshot->width, $screenshot->height]);
        }
        // Image data split over several IDAT chunks, an ancillary chunk before IEND
        $data = (string)gzcompress(self::pngRows(40, 30, false), 9);
        $split = self::chunk('IHDR', pack('NNCCCCC', 40, 30, 8, 6, 0, 0, 0))
            . implode('', array_map(static fn(string $part): string => self::chunk('IDAT', $part), str_split($data, 50)))
            . self::chunk('tEXt', "Software\x00Context Reporter") . self::chunk('IEND', '');
        self::assertSame(40, $validator->validate("\x89PNG\r\n\x1a\n" . $split, 1024 * 1024)->width);
    }

    /**
     * Every PNG filter type (0 None, 1 Sub, 2 Up, 3 Average, 4 Paeth) in the
     * layouts Context Reporter accepts: colour types, bit depths, a palette,
     * odd sizes and Adam7 interlacing.
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function validFilterImages(): iterable
    {
        $cycle = static fn(int $row): int => $row % 5;
        yield 'RGBA 8 bit' => [self::layoutPng(37, 23, 6, 8, false, $cycle), 37, 23];
        yield 'RGB 8 bit, interlaced' => [self::layoutPng(33, 17, 2, 8, true, $cycle), 33, 17];
        yield 'RGBA 16 bit' => [self::layoutPng(9, 7, 6, 16, false, $cycle), 9, 7];
        yield 'grey 1 bit, interlaced' => [self::layoutPng(13, 11, 0, 1, true, $cycle), 13, 11];
        yield 'grey and alpha 8 bit' => [self::layoutPng(5, 9, 4, 8, false, $cycle), 5, 9];
        yield 'palette 4 bit' => [self::layoutPng(19, 6, 3, 4, false, $cycle), 19, 6];
        yield 'palette 2 bit, interlaced, 1 x 1' => [self::layoutPng(1, 1, 3, 2, true, $cycle), 1, 1];
    }

    #[Test]
    #[DataProvider('validFilterImages')]
    public function allFilterTypesAreAcceptedInEveryLayout(string $binary, int $width, int $height): void
    {
        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);

        self::assertSame([$width, $height], [$screenshot->width, $screenshot->height]);
    }

    /**
     * Chunks, CRCs and the compressed stream are valid and the image data has
     * exactly the announced size, but a row starts with a filter type that does
     * not exist: decoders refuse the image.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidFilterImages(): iterable
    {
        yield 'filter 5 in the first row' => [self::layoutPng(40, 30, 6, 8, false, static fn(int $row): int => $row === 0 ? 5 : 0)];
        yield 'filter 255 in a middle row' => [self::layoutPng(40, 30, 6, 8, false, static fn(int $row): int => $row === 17 ? 255 : $row % 5)];
        yield 'filter 5 in the last row' => [self::layoutPng(40, 30, 2, 8, false, static fn(int $row): int => $row === 29 ? 5 : 1)];
        yield 'filter 9 in the last Adam7 pass' => [self::layoutPng(33, 17, 6, 8, true, static fn(int $row, int $pass): int => $pass === 6 && $row === 3 ? 9 : 0)];
        yield 'filter 5 in a palette image' => [self::layoutPng(19, 6, 3, 4, false, static fn(int $row): int => $row === 2 ? 5 : 0)];
        // Spread over many IDAT chunks and inflated pieces: the row position is carried across them
        $rows = self::layoutRows(300, 300, 32, false, static fn(int $row): int => $row === 250 ? 6 : 0);
        $chunks = implode('', array_map(static fn(string $part): string => self::chunk('IDAT', $part), str_split((string)gzcompress($rows, 0), 7000)));
        yield 'filter 6 after many IDAT chunks' => ["\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNCCCCC', 300, 300, 8, 6, 0, 0, 0)) . $chunks . self::chunk('IEND', '')];
    }

    #[Test]
    #[DataProvider('invalidFilterImages')]
    public function rowsWithAnInvalidFilterTypeAreRejectedAsDamaged(string $binary): void
    {
        try {
            (new ScreenshotValidator())->validate($binary, 1024 * 1024);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException $exception) {
            self::assertSame(InvalidScreenshotException::DAMAGED, $exception->getReason());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedImages(): iterable
    {
        $png = self::png(64, 48);
        $jpeg = (string)file_get_contents(self::FIXTURES . 'screenshot.jpg');
        yield 'PNG cut off in the image data' => [substr($png, 0, intdiv(strlen($png), 2))];
        yield 'PNG without IEND' => [substr($png, 0, -12)];
        yield 'PNG with a wrong CRC' => [substr($png, 0, -1) . chr(ord($png[strlen($png) - 1]) ^ 0xFF)];
        yield 'PNG with data after IEND' => [$png . '<script>alert(1)</script>'];
        yield 'PNG with less image data than announced' => [self::png(64, 48, rows: self::pngRows(64, 47, false))];
        yield 'PNG with more image data than announced' => [self::png(64, 48, rows: self::pngRows(64, 49, false))];
        yield 'PNG with an incomplete compressed stream' => [self::png(64, 48, compressed: substr((string)gzcompress(self::pngRows(64, 48, false)), 0, -6))];
        yield 'PNG with corrupted compressed data' => [self::png(64, 48, compressed: "\x78\x9c" . str_repeat("\xff", 40))];
        yield 'PNG without image data' => ["\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNCCCCC', 64, 48, 8, 6, 0, 0, 0)) . self::chunk('IEND', '')];
        yield 'PNG with an unknown critical chunk' => [self::png(64, 48, extra: self::chunk('ABCD', 'x'))];
        yield 'PNG with an invalid bit depth' => [self::png(64, 48, depth: 7)];
        yield 'palette PNG without palette' => [self::png(4, 4, color: 3)];
        yield 'JPEG without EOI' => [substr($jpeg, 0, -2)];
        yield 'JPEG cut off in the scan' => [substr($jpeg, 0, intdiv(strlen($jpeg), 3) * 2)];
        yield 'JPEG cut off in the headers' => [substr($jpeg, 0, 40)];
        yield 'JPEG with data after EOI' => [$jpeg . '<html></html>'];
        yield 'JPEG without scan' => [self::jpegWithoutScan($jpeg)];
    }

    /**
     * K6: an upload that was cut off or corrupted is refused instead of being
     * stored and sent as a broken image.
     */
    #[Test]
    #[DataProvider('damagedImages')]
    public function damagedImagesAreRejected(string $binary): void
    {
        try {
            (new ScreenshotValidator())->validate($binary, 1024 * 1024);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException $exception) {
            self::assertContains($exception->getReason(), [InvalidScreenshotException::DAMAGED, InvalidScreenshotException::FORMAT]);
        }
    }

    #[Test]
    public function truncatedImagesAreReportedAsDamaged(): void
    {
        foreach ([substr(self::png(64, 48), 0, -12), substr((string)file_get_contents(self::FIXTURES . 'screenshot.jpg'), 0, -2)] as $binary) {
            try {
                (new ScreenshotValidator())->validate($binary, 1024 * 1024);
                self::fail('Expected exception was not thrown.');
            } catch (InvalidScreenshotException $exception) {
                self::assertSame(InvalidScreenshotException::DAMAGED, $exception->getReason());
            }
        }
    }

    /**
     * The image data is inflated in small pieces and thrown away: a large blank
     * image (little compressed data, much pixel data) is checked with little
     * memory, and data beyond what the header announces stops the check early.
     */
    #[Test]
    public function checkingTheImageDataIsBounded(): void
    {
        [$width, $height] = [5000, 5000];
        $blank = self::png($width, $height, compressed: self::compressedZeros(($width * 4 + 1) * $height));
        $before = memory_get_peak_usage();
        $started = microtime(true);
        self::assertSame($width, (new ScreenshotValidator())->validate($blank, 15 * 1024 * 1024)->width);
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertLessThan(48 * 1024 * 1024, memory_get_peak_usage() - $before, 'Pixels are never held in memory');

        // Announces 100 x 100 pixels, but the data would inflate to 100 MB
        $bomb = self::png(100, 100, compressed: self::compressedZeros(100 * 1024 * 1024));
        $started = microtime(true);
        try {
            (new ScreenshotValidator())->validate($bomb, 15 * 1024 * 1024);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException $exception) {
            self::assertSame(InvalidScreenshotException::DAMAGED, $exception->getReason());
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'Stops once the data exceeds the announced size');
    }

    private static function png(int $width, int $height, bool $interlaced = false, ?string $rows = null, ?string $compressed = null, string $extra = '', int $depth = 8, int $color = 6): string
    {
        $compressed ??= (string)gzcompress($rows ?? self::pngRows($width, $height, $interlaced));
        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $color, 0, 0, $interlaced ? 1 : 0))
            . $extra
            . self::chunk('IDAT', $compressed)
            . self::chunk('IEND', '');
    }

    /**
     * Filter byte 0 and RGBA pixels per row; for interlaced images per row of each Adam7 pass.
     */
    private static function pngRows(int $width, int $height, bool $interlaced): string
    {
        $passes = $interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $data = '';
        foreach ($passes as [$x, $y, $stepX, $stepY]) {
            $columns = intdiv($width - $x + $stepX - 1, $stepX);
            $rows = intdiv($height - $y + $stepY - 1, $stepY);
            if ($columns > 0 && $rows > 0) {
                $data .= str_repeat("\x00" . str_repeat("\x2b\x6c\xa5\xff", $columns), $rows);
            }
        }
        return $data;
    }

    /**
     * A PNG of any colour type and bit depth (with a palette for type 3) whose
     * rows start with the filter type $filter(row in the pass, pass) returns.
     *
     * @param \Closure(int, int): int $filter
     */
    private static function layoutPng(int $width, int $height, int $color, int $depth, bool $interlaced, \Closure $filter): string
    {
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color];
        $rows = self::layoutRows($width, $height, $channels * $depth, $interlaced, $filter);
        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $color, 0, 0, $interlaced ? 1 : 0))
            . ($color === 3 ? self::chunk('PLTE', str_repeat("\x10\x80\xf0", 1 << $depth)) : '')
            . self::chunk('IDAT', (string)gzcompress($rows))
            . self::chunk('IEND', '');
    }

    /**
     * @param \Closure(int, int): int $filter
     */
    private static function layoutRows(int $width, int $height, int $bitsPerPixel, bool $interlaced, \Closure $filter): string
    {
        $passes = $interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $data = '';
        foreach ($passes as $pass => [$x, $y, $stepX, $stepY]) {
            $columns = intdiv($width - $x + $stepX - 1, $stepX);
            $rows = intdiv($height - $y + $stepY - 1, $stepY);
            for ($row = 0; $columns > 0 && $row < $rows; $row++) {
                $filterType = $filter($row, $pass);
                if ($filterType < 0 || $filterType > 255) {
                    throw new \LogicException(sprintf('The filter type of a PNG row is one byte, %d given.', $filterType), 1763116800);
                }
                // Palette indexes stay below 2^depth: the pixel bytes are 0x00
                $data .= chr($filterType) . str_repeat("\x00", intdiv($columns * $bitsPerPixel + 7, 8));
            }
        }
        return $data;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function compressedZeros(int $size): string
    {
        $context = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        self::assertInstanceOf(\DeflateContext::class, $context);
        $compressed = '';
        $block = str_repeat("\x00", 1024 * 1024);
        for ($remaining = $size; $remaining > 0; $remaining -= strlen($block)) {
            $compressed .= deflate_add($context, $remaining >= strlen($block) ? $block : substr($block, 0, $remaining), ZLIB_NO_FLUSH);
        }
        return $compressed . deflate_add($context, '', ZLIB_FINISH);
    }

    /**
     * The fixture's headers, directly followed by EOI.
     */
    private static function jpegWithoutScan(string $jpeg): string
    {
        $scan = strpos($jpeg, "\xFF\xDA");
        return substr($jpeg, 0, $scan === false ? 2 : $scan) . "\xFF\xD9";
    }
}

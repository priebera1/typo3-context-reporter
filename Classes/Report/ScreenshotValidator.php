<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * Accepts PNG and JPEG only. The format is determined from the file content,
 * never from the client supplied file name or media type.
 *
 * After the header (format, dimensions), the whole file is checked without
 * decoding pixels, so a cut-off or corrupted upload is refused instead of
 * being stored and sent as a broken image:
 *
 *  - PNG: every chunk with its CRC, from IHDR to IEND at the end of the file,
 *    and the compressed image data, inflated in small pieces and thrown away:
 *    it must end exactly after the number of bytes the header announces (so
 *    the work stops at the size the dimensions allow), and every row (of every
 *    Adam7 pass when interlaced) must start with a valid filter type (0-4).
 *    Without PHP's zlib extension (optional for TYPO3) only the chunks are
 *    checked.
 *  - JPEG: every segment from SOI to EOI at the end of the file, with the
 *    tables, the frame and at least one scan with data.
 *
 * Time and memory grow with the file size and the dimension limits only.
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
        $complete = $mediaType === 'image/png' ? self::isCompletePng($content, $width, $height) : self::isCompleteJpeg($content);
        if (!$complete) {
            throw InvalidScreenshotException::create(InvalidScreenshotException::DAMAGED, 'The screenshot is incomplete or damaged.');
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

    private static function isCompletePng(string $content, int $width, int $height): bool
    {
        $length = strlen($content);
        $position = 8;
        $header = null;
        $palette = false;
        $dataState = 0; // 0: before IDAT, 1: in the IDAT sequence, 2: after it
        $inflate = null;
        $expected = 0;
        $inflated = 0;
        $rows = ['layout' => [], 'pass' => 0, 'left' => 0, 'next' => 0];
        while ($position + 12 <= $length) {
            $chunk = unpack('Nlength', $content, $position);
            $chunkLength = is_array($chunk) ? (int)$chunk['length'] : -1;
            $type = substr($content, $position + 4, 4);
            if ($chunkLength < 0 || $chunkLength > $length - $position - 12 || preg_match('/^[A-Za-z]{4}$/D', $type) !== 1) {
                return false;
            }
            $crc = unpack('Ncrc', $content, $position + 8 + $chunkLength);
            if (!is_array($crc) || hash('crc32b', substr($content, $position + 4, $chunkLength + 4)) !== sprintf('%08x', $crc['crc'])) {
                return false;
            }
            $data = substr($content, $position + 8, $chunkLength);
            $position += $chunkLength + 12;
            if ($header === null) {
                // IHDR comes first
                if ($type !== 'IHDR' || $chunkLength !== 13) {
                    return false;
                }
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                $bitsPerPixel = is_array($header) ? self::pngBitsPerPixel((int)$header['color'], (int)$header['depth']) : 0;
                if (!is_array($header) || $bitsPerPixel === 0 || $header['width'] !== $width || $header['height'] !== $height
                    || $header['compression'] !== 0 || $header['filter'] !== 0 || !in_array($header['interlace'], [0, 1], true)
                ) {
                    return false;
                }
                $layout = self::pngRowLayout($width, $height, $bitsPerPixel, $header['interlace'] === 1);
                $expected = array_sum(array_map(static fn(array $pass): int => $pass[0] * $pass[1], $layout));
                $rows = ['layout' => $layout, 'pass' => 0, 'left' => $layout[0][0] ?? 0, 'next' => 0];
                continue;
            }
            if ($type === 'IDAT') {
                if ($dataState === 2 || ($header['color'] === 3 && !$palette)) {
                    return false;
                }
                $dataState = 1;
                if (!function_exists('inflate_init')) {
                    continue;
                }
                $inflate ??= inflate_init(ZLIB_ENCODING_DEFLATE);
                if ($inflate === false) {
                    return false;
                }
                // Small pieces: highly compressed data cannot produce large output at once
                foreach (str_split($data, 4096) as $piece) {
                    if ($piece === '') {
                        continue;
                    }
                    $output = @inflate_add($inflate, $piece, ZLIB_SYNC_FLUSH);
                    if ($output === false || $inflated + strlen($output) > $expected || !self::hasValidRowFilters($output, $inflated, $rows)) {
                        return false;
                    }
                    $inflated += strlen($output);
                }
                continue;
            }
            if ($dataState === 1) {
                $dataState = 2;
            }
            if ($type === 'IEND') {
                if ($chunkLength !== 0 || $position !== $length || $dataState === 0) {
                    return false;
                }
                return !function_exists('inflate_init')
                    || ($inflate instanceof \InflateContext && inflate_get_status($inflate) === ZLIB_STREAM_END && $inflated === $expected);
            }
            if ($type === 'PLTE') {
                $palette = true;
            } elseif ($type === 'IHDR' || ctype_upper($type[0])) {
                // A second header or an unknown critical chunk: decoders refuse the image
                return false;
            }
        }
        return false;
    }

    private static function pngBitsPerPixel(int $colorType, int $depth): int
    {
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$colorType] ?? 0;
        $depths = $colorType === 0 ? [1, 2, 4, 8, 16] : ($colorType === 3 ? [1, 2, 4, 8] : [8, 16]);
        return $channels > 0 && in_array($depth, $depths, true) ? $channels * $depth : 0;
    }

    /**
     * The rows of the inflated image data, as [number of rows, bytes per row]
     * per pass: a row is a filter byte and the pixels; interlaced images
     * consist of the seven Adam7 passes (empty passes are left out).
     *
     * @return list<array{int, int}>
     */
    private static function pngRowLayout(int $width, int $height, int $bitsPerPixel, bool $interlaced): array
    {
        $passes = $interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $layout = [];
        foreach ($passes as [$x, $y, $stepX, $stepY]) {
            $columns = intdiv($width - $x + $stepX - 1, $stepX);
            $rows = intdiv($height - $y + $stepY - 1, $stepY);
            if ($columns > 0 && $rows > 0) {
                $layout[] = [$rows, 1 + intdiv($columns * $bitsPerPixel + 7, 8)];
            }
        }
        return $layout;
    }

    /**
     * Checks the filter bytes in a piece of inflated image data that starts at
     * $offset. $rows tracks where the next row starts across pieces and passes.
     *
     * @param array{layout: list<array{int, int}>, pass: int, left: int, next: int} $rows
     */
    private static function hasValidRowFilters(string $output, int $offset, array &$rows): bool
    {
        $end = $offset + strlen($output);
        while ($rows['next'] < $end && isset($rows['layout'][$rows['pass']])) {
            // PNG filter types: 0 None, 1 Sub, 2 Up, 3 Average, 4 Paeth
            if (ord($output[$rows['next'] - $offset]) > 4) {
                return false;
            }
            $rows['next'] += $rows['layout'][$rows['pass']][1];
            if (--$rows['left'] === 0) {
                $rows['pass']++;
                $rows['left'] = $rows['layout'][$rows['pass']][0] ?? 0;
            }
        }
        return true;
    }

    private static function isCompleteJpeg(string $content): bool
    {
        $length = strlen($content);
        $position = 2;
        $frame = false;
        $quantization = false;
        $huffman = false;
        $scan = false;
        while ($position < $length) {
            if ($content[$position] !== "\xFF") {
                return false;
            }
            // Fill bytes may precede a marker
            while ($position < $length && $content[$position] === "\xFF") {
                $position++;
            }
            if ($position >= $length) {
                return false;
            }
            $marker = ord($content[$position++]);
            if ($marker === 0xD9) {
                // End of image: the last bytes of the file
                return $scan && $position === $length;
            }
            if ($marker === 0x01) {
                continue;
            }
            if ($marker === 0x00 || $marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $position + 2 > $length) {
                return false;
            }
            $segment = unpack('nlength', $content, $position);
            $segmentLength = is_array($segment) ? (int)$segment['length'] : 0;
            if ($segmentLength < 2 || $position + $segmentLength > $length) {
                return false;
            }
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                $components = $segmentLength >= 8 ? ord($content[$position + 7]) : 0;
                if ($frame || $components < 1 || $segmentLength !== 8 + 3 * $components) {
                    return false;
                }
                $frame = true;
                $huffman = $huffman || $marker >= 0xC9;
            } elseif ($marker === 0xDB) {
                $quantization = true;
            } elseif ($marker === 0xC4) {
                $huffman = true;
            }
            $position += $segmentLength;
            if ($marker !== 0xDA) {
                continue;
            }
            // Start of scan: the entropy-coded data runs to the next marker (FF 00 is a data byte, FF D0-D7 a restart)
            if (!$frame || !$quantization || !$huffman) {
                return false;
            }
            $start = $position;
            while (true) {
                $next = strpos($content, "\xFF", $position);
                if ($next === false || $next + 1 >= $length) {
                    return false;
                }
                $following = ord($content[$next + 1]);
                if ($following === 0x00 || ($following >= 0xD0 && $following <= 0xD7)) {
                    $position = $next + 2;
                    continue;
                }
                if ($following === 0xFF) {
                    $position = $next + 1;
                    continue;
                }
                $position = $next;
                break;
            }
            if ($position === $start) {
                return false;
            }
            $scan = true;
        }
        return false;
    }
}

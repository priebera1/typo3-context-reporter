<?php

declare(strict_types=1);

/**
 * A tiny HTTP server for BoundedHttpClientTest: it answers every request with
 * one scripted behaviour on a real socket (chunked, trickling, silent, too
 * large, cut off), which a mocked Guzzle handler cannot reproduce.
 *
 * Usage: php scripted-server.php <scenario>
 * Prints the port it listens on (127.0.0.1) and serves until it is stopped.
 */
$scenario = (string)($argv[1] ?? '');
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, $errorMessage . "\n");
    exit(1);
}
$port = (int)substr((string)strrchr((string)stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

$send = static function ($connection, string $data): bool {
    $result = @fwrite($connection, $data);
    @fflush($connection);
    return $result !== false && $result > 0;
};
$chunk = static fn(string $data): string => dechex(strlen($data)) . "\r\n" . $data . "\r\n";
$acknowledgement = json_encode(['reference' => 'LW-152', 'url' => 'http://127.0.0.1:' . $port . '/app/reports/LW-152'], JSON_THROW_ON_ERROR);

while (is_resource($server)) {
    $connection = @stream_socket_accept($server, 3600);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);
    // Read the request: headers, then a body of Content-Length bytes
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $request .= (string)fread($connection, 8192);
    }
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $request, $matches) === 1) {
        $remaining = (int)$matches[1] - (strlen($request) - (int)strpos($request, "\r\n\r\n") - 4);
        while ($remaining > 0 && !feof($connection)) {
            $piece = (string)fread($connection, min(65536, $remaining));
            $remaining -= strlen($piece);
        }
    }

    switch ($scenario) {
        case 'chunked':
            // The acknowledgement in three chunks, 150 ms apart
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            foreach (str_split($acknowledgement, max(1, (int)ceil(strlen($acknowledgement) / 3))) as $part) {
                $send($connection, $chunk($part));
                usleep(150000);
            }
            $send($connection, "0\r\n\r\n");
            break;
        case 'pieces':
            // Content-Length, the body in pieces
            $send($connection, "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: " . strlen($acknowledgement) . "\r\nConnection: close\r\n\r\n");
            foreach (str_split($acknowledgement, 7) as $part) {
                $send($connection, $part);
                usleep(20000);
            }
            break;
        case 'trickle':
            // Headers at once, then one byte every 100 ms: about 7 minutes in total
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 4096\r\nConnection: close\r\n\r\n");
            for ($i = 0; $i < 4096 && $send($connection, $i === 0 ? '{' : ' '); $i++) {
                usleep(100000);
            }
            break;
        case 'trickleChunked':
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            for ($i = 0; $i < 4096 && $send($connection, $chunk($i === 0 ? '{' : ' ')); $i++) {
                usleep(100000);
            }
            break;
        case 'slowHeaders':
            // The status line and headers, one byte every 100 ms, never complete
            foreach (str_split("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n" . str_repeat('X-Padding: slow' . "\r\n", 200)) as $byte) {
                if (!$send($connection, $byte)) {
                    break;
                }
                usleep(100000);
            }
            break;
        case 'silent':
            sleep(60);
            break;
        case 'largeDeclared':
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 1048576\r\nConnection: close\r\n\r\n");
            $i = 0;
            while ($i++ < 128 && $send($connection, str_repeat('a', 8192))) {
                usleep(1000);
            }
            break;
        case 'largeChunked':
            // No length: the limit has to be found while reading
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            $i = 0;
            while ($i++ < 128 && $send($connection, $chunk(str_repeat('a', 8192)))) {
                usleep(1000);
            }
            $send($connection, "0\r\n\r\n");
            break;
        case 'truncated':
            // Promises 500 bytes, sends the start of the acknowledgement and closes
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 500\r\nConnection: close\r\n\r\n" . substr($acknowledgement, 0, 30));
            break;
        case 'malformed':
            $send($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 17\r\nConnection: close\r\n\r\n{\"reference\":\"LW-");
            break;
        default:
            $send($connection, "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
    @fclose($connection);
}

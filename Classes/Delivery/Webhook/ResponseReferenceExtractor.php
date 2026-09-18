<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

/**
 * Picks an external reference (ticket number, issue key, ...) and a link out
 * of a webhook response, so the report history can point to the ticket that
 * the receiving system created.
 *
 * Receivers may answer with JSON such as {"reference": "SUP-42", "url": "https://..."}.
 *
 * @internal
 */
final class ResponseReferenceExtractor
{
    private const REFERENCE_KEYS = ['reference', 'ticketId', 'ticket_id', 'issueKey', 'issue_key', 'key', 'number', 'id'];
    private const URL_KEYS = ['url', 'html_url', 'web_url', 'link', 'ticketUrl', 'ticket_url'];
    private const CONTAINER_KEYS = ['data', 'ticket', 'issue', 'result'];

    /**
     * @return array{reference: string, url: string}
     */
    public function extract(string $body, string $contentType): array
    {
        $empty = ['reference' => '', 'url' => ''];
        $body = trim($body);
        if ($body === '' || strlen($body) > 65536) {
            return $empty;
        }
        if (!str_contains(strtolower($contentType), 'json') && !str_starts_with($body, '{')) {
            return $empty;
        }
        $decoded = json_decode($body, true, 8);
        if (!is_array($decoded)) {
            return $empty;
        }

        $candidates = [$decoded];
        foreach (self::CONTAINER_KEYS as $containerKey) {
            if (is_array($decoded[$containerKey] ?? null)) {
                $candidates[] = $decoded[$containerKey];
            }
        }

        $reference = '';
        $url = '';
        foreach ($candidates as $candidate) {
            if ($reference === '') {
                $reference = $this->findReference($candidate);
            }
            if ($url === '') {
                $url = $this->findUrl($candidate);
            }
        }
        return ['reference' => $reference, 'url' => $url];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function findReference(array $data): string
    {
        foreach (self::REFERENCE_KEYS as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $value = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub((string)$value, 'UTF-8')));
                if ($value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        }
        return '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function findUrl(array $data): string
    {
        foreach (self::URL_KEYS as $key) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || strlen($value) > 1024 || filter_var($value, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
            if ($scheme === 'https' || $scheme === 'http') {
                return $value;
            }
        }
        return '';
    }
}

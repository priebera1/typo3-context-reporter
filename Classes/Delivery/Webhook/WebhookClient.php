<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\HubIntake;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Priebera\ContextReporter\Delivery\Http\HttpExchange;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;

/**
 * Posts a JSON document to the configured webhook endpoint, signed with
 * HMAC-SHA256 and/or authenticated with the configured header.
 *
 * The timeout is a total deadline (connection, headers and body), the answer
 * is read completely up to 64 KB, and redirects are not followed
 * (BoundedHttpClient). Failure messages, references and links never contain
 * the endpoint or configured secrets.
 *
 * @internal
 */
final readonly class WebhookClient
{
    public function __construct(
        private BoundedHttpClient $http,
        private ResponseReferenceExtractor $referenceExtractor,
        private ExtensionInfo $extensionInfo,
    ) {}

    /**
     * The generic webhook: any 2xx answer means delivered; a reference and a
     * link are taken from a complete JSON answer when it has them.
     *
     * @param string $event Sent as X-Context-Reporter-Event
     * @param array<string, string> $headers Additional X-Context-Reporter-* headers
     */
    public function post(WebhookSettings $settings, string $event, string $body, array $headers = []): DeliveryOutcome
    {
        $exchange = $this->exchange($settings, $event, $body, $headers);
        $secrets = $settings->getSecrets();
        if (!$exchange->isSuccessfulStatus()) {
            return self::failure($exchange, $secrets);
        }
        $status = (int)$exchange->status;
        if (!$exchange->isBodyComplete()) {
            // Delivered (2xx), but the answer could not be read: no reference
            return DeliveryOutcome::success('HTTP ' . $status . ' (' . self::problemText($exchange->problem) . ')', $status);
        }
        $reference = $this->referenceExtractor->extract($exchange->body, $exchange->contentType);
        return DeliveryOutcome::success(
            'HTTP ' . $status,
            $status,
            ExternalReferenceSanitizer::reference($reference['reference'], $secrets),
            ExternalReferenceSanitizer::url($reference['url'], $secrets),
        );
    }

    /**
     * The signed request and what came back, for a receiver whose answer is
     * judged by its destination (the Context Reporter Hub).
     *
     * @param array<string, string> $headers Additional X-Context-Reporter-* headers
     */
    public function exchange(WebhookSettings $settings, string $event, string $body, array $headers = []): HttpExchange
    {
        $timestamp = time();
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json, text/plain;q=0.9, */*;q=0.8',
            'User-Agent' => 'TYPO3-Context-Reporter/' . ($this->extensionInfo->getVersion() ?: 'dev'),
            'X-Context-Reporter-Event' => $event,
        ] + $headers + [
            'X-Context-Reporter-Timestamp' => (string)$timestamp,
        ];
        if ($settings->secret !== '') {
            $headers[WebhookSignature::HEADER] = WebhookSignature::create($settings->secret, $timestamp, $body);
        }
        if ($settings->authHeaderName !== '') {
            $headers[$settings->authHeaderName] = $settings->authHeaderValue;
        }
        // A Hub intake URL (the Hub destination, or a webhook sending to a Hub
        // site) gives the upload of a large screenshot time of its own (CRI-4);
        // every other receiver keeps its configured limit.
        $timeout = (float)$settings->timeoutSeconds;
        if (HubIntake::isIntakeUrl($settings->url)) {
            $timeout = max($timeout, HubIntake::deadlineSeconds(strlen($body)));
        }
        return $this->http->request('POST', $settings->url, $headers, $body, $timeout, $settings->getSecrets(), min(5.0, (float)$settings->timeoutSeconds));
    }

    /**
     * A failed exchange as a delivery outcome: no answer, or a status outside
     * 2xx with an excerpt of the answer (without secrets).
     *
     * @param list<string> $secrets
     */
    public static function failure(HttpExchange $exchange, array $secrets): DeliveryOutcome
    {
        if (!$exchange->hasStatus()) {
            return DeliveryOutcome::failure($exchange->message);
        }
        $status = (int)$exchange->status;
        $excerpt = $exchange->isBodyComplete() ? SafeErrorMessage::sanitize($exchange->body, $secrets, 300) : '';
        $message = 'HTTP ' . $status . ($status >= 300 && $status < 400 ? ' (redirects are not followed)' : '');
        return DeliveryOutcome::failure($message . ($excerpt !== '' ? ': ' . $excerpt : ''), $status);
    }

    public static function problemText(string $problem): string
    {
        return match ($problem) {
            HttpExchange::PROBLEM_TOO_LARGE => 'the answer was larger than 64 KB and was not read',
            default => 'the answer did not arrive completely in time',
        };
    }
}

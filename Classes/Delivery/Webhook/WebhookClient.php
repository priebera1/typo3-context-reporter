<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Posts a JSON document to the configured webhook endpoint, signed with
 * HMAC-SHA256 and/or authenticated with the configured header.
 *
 * Redirects are not followed and the response is read only partially.
 * Failure messages, references and links never contain the endpoint or
 * configured secrets.
 *
 * @internal
 */
final readonly class WebhookClient
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private RequestFactory $requestFactory,
        private ResponseReferenceExtractor $referenceExtractor,
        private ExtensionInfo $extensionInfo,
    ) {}

    /**
     * @param string $event Sent as X-Context-Reporter-Event
     * @param array<string, string> $headers Additional X-Context-Reporter-* headers
     */
    public function post(WebhookSettings $settings, string $event, string $body, array $headers = []): DeliveryOutcome
    {
        $secrets = $settings->getSecrets();
        try {
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

            $response = $this->requestFactory->request($settings->url, 'POST', [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $settings->timeoutSeconds,
                'connect_timeout' => min(5, $settings->timeoutSeconds),
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
            ]);
            $responseBody = $response->getBody()->read(self::MAX_RESPONSE_BYTES);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $secrets));
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            $reference = $this->referenceExtractor->extract($responseBody, $response->getHeaderLine('Content-Type'));
            return DeliveryOutcome::success(
                'HTTP ' . $status,
                $status,
                ExternalReferenceSanitizer::reference($reference['reference'], $secrets),
                ExternalReferenceSanitizer::url($reference['url'], $secrets),
            );
        }

        $excerpt = SafeErrorMessage::sanitize($responseBody, $secrets, 300);
        $message = 'HTTP ' . $status . ($status >= 300 && $status < 400 ? ' (redirects are not followed)' : '');
        return DeliveryOutcome::failure($message . ($excerpt !== '' ? ': ' . $excerpt : ''), $status);
    }
}

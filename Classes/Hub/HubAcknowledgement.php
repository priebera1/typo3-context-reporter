<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Hub;

use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\Http\HttpExchange;
use Priebera\ContextReporter\Delivery\Webhook\ExternalReferenceSanitizer;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;

/**
 * Judges the Hub's answer to a report delivery. Unlike a generic webhook,
 * where any 2xx means delivered, the Hub confirms a report with
 * {"reference": "LW-152", "url": "…"}: only such an acknowledgement counts as
 * received. A 2xx without a usable acknowledgement (empty, malformed, too large,
 * cut off) is a failed delivery that can be retried: the Hub keeps one report
 * per report ID and answers a retry with the existing reference.
 *
 * Three facts are kept apart: the transport succeeded (2xx), the
 * acknowledgement is valid (a Hub reference), and a link to the report is
 * available (only on the trusted Hub address, see WebhookSettings::getHubLinkBase()).
 * Newer Hubs add whether the screenshot was stored and the storage state of
 * the workspace (HubReceipt); a report received without its screenshot is
 * still received.
 *
 * A delivery whose answer was lost after the request may have reached the Hub
 * (a timeout, a connection closed while waiting, a 2xx answer cut off, a proxy's
 * 502/504 because the Hub did not answer it in time) is not
 * confirmed, but the Hub may have stored the report: the Hub stores first and
 * answers then. Such a failure starts with UNCONFIRMED and says that a retry is
 * safe, so neither the reporter nor the history claims it was not received.
 *
 * @internal
 */
final class HubAcknowledgement
{
    public const REFERENCE = '/^[A-Z]{1,5}-[0-9]{1,9}$/D';
    public const UNCONFIRMED = 'Not confirmed: ';
    private const RETRY_IS_SAFE = ' The Hub may have received the report; a retry is safe: the Hub keeps one report per report ID and answers a retry with its reference.';

    /**
     * Whether a failed Hub delivery is unconfirmed (it may have been received),
     * from its stored message.
     */
    public static function isUnconfirmed(string $message): bool
    {
        return str_starts_with($message, self::UNCONFIRMED);
    }

    public static function outcome(HttpExchange $exchange, WebhookSettings $hub): DeliveryOutcome
    {
        $secrets = $hub->getSecrets();
        if (!$exchange->hasStatus() && $exchange->mayHaveArrived) {
            return DeliveryOutcome::failure(self::UNCONFIRMED . $exchange->message . self::RETRY_IS_SAFE);
        }
        if (in_array($exchange->status, [502, 504], true)) {
            // A proxy in front of the Hub gave up waiting for it; the Hub may have stored the report meanwhile
            return DeliveryOutcome::failure(self::UNCONFIRMED . WebhookClient::failure($exchange, $secrets)->message . '.' . self::RETRY_IS_SAFE, $exchange->status);
        }
        if (!$exchange->isSuccessfulStatus()) {
            return WebhookClient::failure($exchange, $secrets);
        }
        $status = (int)$exchange->status;
        if (!$exchange->isBodyComplete()) {
            $message = 'HTTP ' . $status . ': the Hub did not confirm the report (' . WebhookClient::problemText($exchange->problem) . ')';
            // Cut off in time: the Hub was answering 2xx, which it does after storing. Too large: not the Hub's answer.
            return $exchange->problem === HttpExchange::PROBLEM_INCOMPLETE
                ? DeliveryOutcome::failure(self::UNCONFIRMED . $message . '.' . self::RETRY_IS_SAFE, $status)
                : DeliveryOutcome::failure($message, $status);
        }
        try {
            $data = json_decode($exchange->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : '';
        if (preg_match(self::REFERENCE, $reference) !== 1) {
            return DeliveryOutcome::failure('HTTP ' . $status . ': the Hub did not confirm the report (no Hub reference in its answer)', $status);
        }
        $url = is_string($data['url'] ?? null) ? $data['url'] : '';
        // Optional: whether the screenshot was stored, how full the workspace is
        return DeliveryOutcome::success('HTTP ' . $status, $status, $reference, self::trustedLink($url, $hub), HubReceipt::fromAnswer($data));
    }

    /**
     * The Hub answers a test delivery with {"status": "ok"}; any other 2xx
     * answer did not come from the Hub (a proxy, a login page).
     */
    public static function testOutcome(HttpExchange $exchange, WebhookSettings $hub): DeliveryOutcome
    {
        if (!$exchange->isSuccessfulStatus()) {
            return WebhookClient::failure($exchange, $hub->getSecrets());
        }
        $status = (int)$exchange->status;
        try {
            $data = $exchange->isBodyComplete() ? json_decode($exchange->body, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
            return DeliveryOutcome::failure('HTTP ' . $status . ': the answer is not the confirmation of Context Reporter Hub', $status);
        }
        return DeliveryOutcome::success('HTTP ' . $status, $status);
    }

    /**
     * A link to a report in the Hub, kept only on the trusted Hub address and
     * without secrets; also applied again when stored links are displayed.
     */
    public static function trustedLink(string $url, WebhookSettings $hub): string
    {
        return HubLink::trusted(ExternalReferenceSanitizer::url($url, $hub->getSecrets()), $hub->getHubLinkBase());
    }
}

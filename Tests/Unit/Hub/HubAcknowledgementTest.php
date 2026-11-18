<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Hub;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\Http\HttpExchange;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubAcknowledgementTest extends UnitTestCase
{
    #[Test]
    public function aSuccessfulStatusWhoseAnswerWasCutOffIsUnconfirmed(): void
    {
        // The Hub answered 200 (it had stored the report), but the answer did not arrive completely in time
        $outcome = HubAcknowledgement::outcome(HttpExchange::bodyUnusable(200, 'application/json', HttpExchange::PROBLEM_INCOMPLETE), $this->hub());

        self::assertFalse($outcome->successful);
        self::assertSame(200, $outcome->responseCode);
        self::assertTrue(HubAcknowledgement::isUnconfirmed($outcome->message), $outcome->message);
        self::assertStringContainsString('the Hub did not confirm the report (the answer did not arrive completely in time)', $outcome->message);
        self::assertStringContainsString('a retry is safe', $outcome->message);
    }

    #[Test]
    public function anAnswerThatIsNotTheHubsStaysAPlainFailure(): void
    {
        // A 2xx that is not a Hub acknowledgement came from something else (a proxy, a login page)
        foreach ([
            HttpExchange::bodyUnusable(200, 'application/json', HttpExchange::PROBLEM_TOO_LARGE),
            HttpExchange::answered(200, '<html>Sign in</html>', 'text/html'),
            HttpExchange::answered(503, '{"error":"unavailable"}', 'application/json'),
            HttpExchange::bodyUnusable(500, 'application/json', HttpExchange::PROBLEM_INCOMPLETE),
            HttpExchange::noAnswer('The server could not be reached (connection refused or blocked) (cURL error 7).'),
        ] as $exchange) {
            $outcome = HubAcknowledgement::outcome($exchange, $this->hub());
            self::assertFalse($outcome->successful);
            self::assertFalse(HubAcknowledgement::isUnconfirmed($outcome->message), $outcome->message);
        }
    }

    #[Test]
    public function aLostAnswerIsUnconfirmed(): void
    {
        $outcome = HubAcknowledgement::outcome(HttpExchange::noAnswer('No answer within the time limit (timeout) (cURL error 28).', true), $this->hub());

        self::assertFalse($outcome->successful);
        self::assertNull($outcome->responseCode);
        self::assertSame(
            'Not confirmed: No answer within the time limit (timeout) (cURL error 28). The Hub may have received the report; a retry is safe: the Hub keeps one report per report ID and answers a retry with its reference.',
            $outcome->message,
        );
    }

    #[Test]
    public function aProxyThatGaveUpOnTheHubIsUnconfirmed(): void
    {
        foreach ([502, 504] as $status) {
            $outcome = HubAcknowledgement::outcome(HttpExchange::answered($status, '<html>Gateway Time-out</html>', 'text/html'), $this->hub());
            self::assertFalse($outcome->successful);
            self::assertSame($status, $outcome->responseCode);
            self::assertTrue(HubAcknowledgement::isUnconfirmed($outcome->message), $outcome->message);
            self::assertStringContainsString('HTTP ' . $status, $outcome->message);
        }
    }

    #[Test]
    public function onlyTheMarkerAtTheStartCounts(): void
    {
        self::assertFalse(HubAcknowledgement::isUnconfirmed(''));
        self::assertFalse(HubAcknowledgement::isUnconfirmed('HTTP 500: Not confirmed: by the receiver'));
    }

    private function hub(): WebhookSettings
    {
        return (new SettingsProviderStub(['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']]))->get()->hub;
    }
}

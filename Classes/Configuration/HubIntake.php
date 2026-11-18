<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * What a site in Context Reporter Hub expects: its webhook URL ends in
 * /in/s_<token> and every delivery is signed with the site's signing secret
 * (whsec_…). The Hub receives the same signed webhook request as any other
 * receiver; these rules only catch values that cannot belong to a Hub site,
 * e.g. the secret pasted into the URL field.
 *
 * @internal
 */
final class HubIntake
{
    /**
     * Total time limit of a delivery (connecting, uploading, waiting for and
     * reading the answer). The reporter waits for it after "Send report", and
     * the email follows it, so a Hub that does not answer must fail quickly. A
     * healthy Hub answers within a second plus the upload of the screenshot; a
     * large screenshot over a slow uplink can run out of time. Such a delivery
     * is failed and can be retried: the Hub stores a report ID only once.
     */
    public const TIMEOUT_SECONDS = 5;

    /**
     * The upload of a large screenshot gets time of its own on top of
     * TIMEOUT_SECONDS (CRI-4): the first UPLOAD_INCLUDED_BYTES of the request
     * body are within the 5 seconds, every further UPLOAD_BYTES_PER_SECOND
     * (256 KiB, an uplink of about 2 Mbit/s) add one second, so a report with
     * the default maximum screenshot (5 MB, a body of about 6.8 MB) still
     * reaches the Hub over such an uplink instead of failing on every retry.
     * A delivery without a large screenshot keeps the 5-second limit (a Hub
     * that does not answer still fails quickly); no delivery waits longer than
     * MAX_TIMEOUT_SECONDS.
     */
    public const UPLOAD_INCLUDED_BYTES = 1048576;

    public const UPLOAD_BYTES_PER_SECOND = 262144;

    public const MAX_TIMEOUT_SECONDS = 30;

    /**
     * Total time limit of a Hub delivery whose request body has this size.
     */
    public static function deadlineSeconds(int $bodyBytes): float
    {
        $upload = max(0, $bodyBytes - self::UPLOAD_INCLUDED_BYTES) / self::UPLOAD_BYTES_PER_SECOND;
        return min((float)self::MAX_TIMEOUT_SECONDS, self::TIMEOUT_SECONDS + $upload);
    }

    /**
     * An optional path prefix allows a Hub behind a reverse proxy path.
     */
    private const PATH = '~^(?:/[A-Za-z0-9._\~-]+)*/in/s_[A-Za-z0-9]{20,40}$~D';
    private const SECRET = '/^whsec_[A-Za-z0-9_-]{16,200}$/D';
    private const MAX_URL_LENGTH = 2048;

    /**
     * An http(s) URL without credentials, query or fragment whose path ends in
     * /in/s_<token>. Whether http is allowed is decided separately.
     */
    public static function isIntakeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/\s/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (string)($parts['host'] ?? '') === ''
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            return false;
        }
        return preg_match(self::PATH, (string)($parts['path'] ?? '')) === 1;
    }

    /**
     * The address of the Hub in the browser ("Hub address for links"): an
     * http(s) URL without credentials, query or fragment, optionally with the
     * path the Hub lives under. Whether http is allowed is decided separately.
     */
    public static function isPublicUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/\s/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && (string)($parts['host'] ?? '') !== ''
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && preg_match('~^(?:/[A-Za-z0-9._\~-]+)*/?$~D', (string)($parts['path'] ?? '')) === 1;
    }

    public static function isSigningSecret(string $secret): bool
    {
        return preg_match(self::SECRET, $secret) === 1;
    }

    /**
     * The site token of a Hub intake URL (s_… of /in/s_…), or '' for any other URL.
     * Redacted like a secret, also when it appears without the rest of the URL.
     */
    public static function siteToken(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return self::isIntakeUrl($url) && preg_match('~/in/(s_[A-Za-z0-9]{20,40})$~D', $path, $token) === 1 ? $token[1] : '';
    }
}

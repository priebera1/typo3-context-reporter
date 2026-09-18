<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * Creates short, human friendly report identifiers such as "CR-7K3Q-9XMA-2B4F".
 * The alphabet is Crockford base32 (no I, L, O, U), so identifiers can be read
 * out on the phone. 60 random bits make collisions within one installation
 * practically impossible; the database enforces uniqueness anyway.
 *
 * @internal
 */
final class ReportIdGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const PATTERN = '/^CR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/D';

    public function generate(): string
    {
        $characters = '';
        for ($i = 0; $i < 12; $i++) {
            $characters .= self::ALPHABET[random_int(0, 31)];
        }
        return 'CR-' . implode('-', str_split($characters, 4));
    }

    public static function isValid(string $identifier): bool
    {
        return preg_match(self::PATTERN, $identifier) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

/**
 * Times in reports: ISO 8601 in the time zone of the TYPO3 installation
 * ($GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone']), the zone TYPO3 shows
 * times in, with the offset that applied at that time.
 *
 * @internal
 */
final class ServerTime
{
    public static function format(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * Creation and last change of a record, from the fields the table
     * declares for them ("ctrl.crdate", "ctrl.tstamp"); unset times are left out.
     *
     * @param array<string, mixed> $row
     * @return array{createdAt?: string, changedAt?: string}
     */
    public static function describeRecord(array $row, string $creationTimeField, string $changeTimeField): array
    {
        $times = [];
        foreach (['createdAt' => $creationTimeField, 'changedAt' => $changeTimeField] as $key => $field) {
            $timestamp = $field !== '' && is_numeric($row[$field] ?? null) ? (int)$row[$field] : 0;
            if ($timestamp > 0) {
                $times[$key] = self::format($timestamp);
            }
        }
        return $times;
    }
}

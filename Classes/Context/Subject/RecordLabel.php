<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The title TYPO3 shows for a record, without free text: TYPO3 falls back
 * to other fields when the label field is empty (label_alt), e.g. to the
 * body text of a content element, which is content, not a title.
 *
 * @internal
 */
final readonly class RecordLabel
{
    private const MAX_LENGTH = 200;

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @param array<string, mixed> $record An access checked record
     */
    public function create(string $table, array $record): string
    {
        foreach ($this->tca->getContentFields($table) as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = '';
            }
        }
        try {
            $label = (string)BackendUtility::getRecordTitle($table, $record, false, false);
        } catch (\Throwable) {
            return '';
        }
        $label = trim((string)preg_replace('/\s+/', ' ', strip_tags($label)));
        return mb_strlen($label) > self::MAX_LENGTH ? mb_substr($label, 0, self::MAX_LENGTH) . '…' : $label;
    }
}

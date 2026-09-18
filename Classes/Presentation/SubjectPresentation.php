<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

/**
 * What a report is about, prepared for people: the kind of object, its
 * name, the technical identifier, where it lives and the surrounding
 * context. Used by the report dialog and the report history.
 *
 * @internal
 */
final readonly class SubjectPresentation
{
    /** Facts that describe the surroundings, in the order of the one-line summary */
    private const META_FACTS = ['site', 'language', 'workspace', 'module'];

    /**
     * A short title for the report list, e.g. the record type instead of a long record title
     */
    public string $listTitle;

    /**
     * @param string $kind The subject type (backend, page, record, file, folder)
     * @param string $typeLabel e.g. "Page Content · Text & Media"
     * @param string $title e.g. the page title, record label or file name
     * @param string $identifier e.g. "UID 12", "tt_content:12", "sys_file:3" or "1:/user_upload/"
     * @param string $location Short hint where the object lives, e.g. the page title of a record
     * @param list<array{key: string, label: string, value: string}> $facts
     * @param string $backendUrl Shareable backend link to the object
     * @param string $listTitle Defaults to the title
     */
    public function __construct(
        public string $kind,
        public string $iconIdentifier,
        public string $typeLabel,
        public string $title,
        public string $identifier,
        public string $location = '',
        public array $facts = [],
        public string $backendUrl = '',
        public string $frontendUrl = '',
        string $listTitle = '',
    ) {
        $this->listTitle = $listTitle !== '' ? $listTitle : $title;
    }

    /**
     * Site, language, workspace and module in one line, e.g. "main · English · Live · Web › List".
     */
    public function getMetaLine(): string
    {
        $values = [];
        foreach ($this->facts as $fact) {
            if (in_array($fact['key'], self::META_FACTS, true)) {
                $values[] = $fact['value'];
            }
        }
        return implode(' · ', $values);
    }

    /**
     * The data the report dialog renders. Links are left out on purpose.
     *
     * @return array{kind: string, icon: string, typeLabel: string, title: string, identifier: string, location: string, meta: string, facts: list<array{key: string, label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'icon' => $this->iconIdentifier,
            'typeLabel' => $this->typeLabel,
            'title' => $this->title,
            'identifier' => $this->identifier,
            'location' => $this->location,
            'meta' => $this->getMetaLine(),
            'facts' => $this->facts,
        ];
    }
}

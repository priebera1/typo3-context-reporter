<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * The technical context of a report as collected on the server and reviewed by
 * the reporter: summary, subject, project, reporter, context sections, system
 * and browser details. It contains JSON compatible scalar data only.
 */
final readonly class ContextDocument
{
    public const TOP_LEVEL_SECTIONS = ['project', 'reporter', 'system', 'browser'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $normalized = [
            'summary' => is_string($data['summary'] ?? null) ? $data['summary'] : '',
            'subject' => is_array($data['subject'] ?? null) ? $data['subject'] : ['type' => SubjectType::Backend->value],
        ];
        foreach (['project', 'reporter', 'context', 'system', 'browser'] as $section) {
            if (is_array($data[$section] ?? null) && $data[$section] !== []) {
                $normalized[$section] = $data[$section];
            }
        }
        return new self($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function getSummary(): string
    {
        return $this->data['summary'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubject(): array
    {
        return $this->data['subject'];
    }

    public function getSubjectType(): SubjectType
    {
        return SubjectType::tryFrom((string)($this->data['subject']['type'] ?? '')) ?? SubjectType::Backend;
    }

    public function getSubjectLabel(): string
    {
        $label = $this->data['subject']['label'] ?? '';
        return is_scalar($label) ? (string)$label : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSection(string $name): array
    {
        $section = $this->data[$name] ?? [];
        return is_array($section) ? $section : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSection(string $name): array
    {
        $section = $this->data['context'][$name] ?? [];
        return is_array($section) ? $section : [];
    }

    public function getPageUid(): int
    {
        $uid = $this->getContextSection('page')['uid'] ?? 0;
        return is_int($uid) ? $uid : 0;
    }

    public function getSiteIdentifier(): string
    {
        $identifier = $this->getContextSection('site')['identifier'] ?? '';
        return is_string($identifier) ? $identifier : '';
    }
}

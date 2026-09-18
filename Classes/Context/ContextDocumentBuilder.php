<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\Routing\BackendRouteResolver;
use Priebera\ContextReporter\Context\Subject\ResolvedSubject;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Context\Subject\SubjectResolver;
use Priebera\ContextReporter\Context\Summary\ContextSummaryBuilder;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\SubjectType;
use Priebera\ContextReporter\Security\ConfiguredSecrets;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Collects the technical context of a report: resolves the subject, lets
 * every collector contribute its section and adds subject and summary.
 *
 * @internal
 */
final readonly class ContextDocumentBuilder
{
    /**
     * @param iterable<ContextCollectorInterface> $collectors
     */
    public function __construct(
        private iterable $collectors,
        private SubjectResolver $subjectResolver,
        private BackendRouteResolver $routeResolver,
        private ContextSummaryBuilder $summaryBuilder,
        private BackendLinkBuilder $links,
        private LoggerInterface $logger,
        private ConfiguredSecrets $secrets,
    ) {}

    /**
     * @throws SubjectNotAvailableException
     */
    public function build(CollectionRequest $request, BackendUserAuthentication $backendUser, ServerRequestInterface $httpRequest): ContextDocument
    {
        $route = $this->routeResolver->resolve($request->location->path, $httpRequest);
        $subject = $this->subjectResolver->resolve($request, $route, $backendUser);
        $scope = new CollectionScope($request, $subject, $route, $backendUser, $httpRequest);

        $document = ['context' => []];
        foreach ($this->collectors as $collector) {
            try {
                $section = $collector->collect($scope);
            } catch (\Throwable $exception) {
                // The report is created without the section; the log explains why
                $this->logger->warning(
                    'Context collector {collector} failed: {error} ({location})',
                    ['collector' => $collector::class] + SafeErrorMessage::logContext($exception, $this->secrets->get()),
                );
                continue;
            }
            if ($section === []) {
                continue;
            }
            $key = $collector->getSectionKey();
            if (in_array($key, ContextDocument::TOP_LEVEL_SECTIONS, true)) {
                $document[$key] = $section;
            } else {
                $document['context'][$key] = $section;
            }
        }

        $document['subject'] = $this->describeSubject($subject, $document['context']);
        $document['summary'] = $this->summaryBuilder->build($document);
        return ContextDocument::fromArray($document);
    }

    /**
     * @param array<string, array<string, mixed>> $context
     * @return array<string, mixed>
     */
    private function describeSubject(ResolvedSubject $subject, array $context): array
    {
        $record = $context['record'] ?? [];
        $page = $context['page'] ?? [];
        $backend = $context['backend'] ?? [];
        $folder = $context['folder'] ?? [];

        if ($subject->type === SubjectType::File && $subject->file !== null) {
            $file = $context['file'] ?? [];
            return array_filter([
                'type' => SubjectType::File->value,
                'table' => $subject->table,
                'uid' => $subject->uid,
                'label' => $subject->file->getName(),
                'typeLabel' => 'File',
                'identifier' => $subject->file->getCombinedIdentifier(),
                'backendUrl' => $file['backendUrl'] ?? '',
            ], static fn(mixed $value): bool => $value !== '');
        }
        if ($subject->type === SubjectType::Folder && $subject->folder !== null) {
            return array_filter([
                'type' => SubjectType::Folder->value,
                'label' => $folder['name'] ?? $subject->folder->getName(),
                'typeLabel' => 'Folder',
                'identifier' => $subject->folder->getCombinedIdentifier(),
                'backendUrl' => $folder['backendUrl'] ?? '',
            ], static fn(mixed $value): bool => $value !== '');
        }

        if ($subject->type === SubjectType::Record && $subject->isNewRecord) {
            return array_filter([
                'type' => SubjectType::Record->value,
                'table' => $subject->table,
                'isNew' => true,
                'label' => 'New record',
                'typeLabel' => $record['tableTitle'] ?? $subject->table,
                'pid' => $subject->pageUid,
            ], static fn(mixed $value): bool => $value !== '');
        }
        if ($subject->type === SubjectType::Record) {
            return array_filter([
                'type' => SubjectType::Record->value,
                'table' => $subject->table,
                'uid' => $subject->uid,
                'label' => $record['label'] ?? '',
                'typeLabel' => $record['type']['label'] ?? $record['tableTitle'] ?? $subject->table,
                'backendUrl' => $record['backendUrl'] ?? '',
            ], static fn(mixed $value): bool => $value !== '');
        }
        if ($subject->type === SubjectType::Page) {
            $doktypeLabel = (string)($page['doktypeLabel'] ?? '');
            $isStandardPage = (int)($page['doktype'] ?? 1) === 1;
            return array_filter([
                'type' => SubjectType::Page->value,
                'table' => 'pages',
                'uid' => $subject->uid,
                'label' => $page['title'] ?? '',
                'typeLabel' => 'Page' . (!$isStandardPage && $doktypeLabel !== '' ? ' (' . $doktypeLabel . ')' : ''),
                'backendUrl' => $page['backendUrl'] ?? '',
            ], static fn(mixed $value): bool => $value !== '');
        }

        $module = $backend['module'] ?? [];
        if (isset($module['identifier'])) {
            return array_filter([
                'type' => SubjectType::Backend->value,
                'label' => implode(' › ', array_filter([(string)($module['group'] ?? ''), (string)($module['title'] ?? '')])),
                'typeLabel' => 'Backend module',
                'module' => (string)$module['identifier'],
                'backendUrl' => $this->links->module((string)$module['identifier']),
            ], static fn(mixed $value): bool => $value !== '');
        }
        $route = (string)($backend['route']['identifier'] ?? '');
        return array_filter([
            'type' => SubjectType::Backend->value,
            'label' => $route !== '' ? $route : 'TYPO3 backend',
            'typeLabel' => 'Backend view',
        ], static fn(mixed $value): bool => $value !== '');
    }
}

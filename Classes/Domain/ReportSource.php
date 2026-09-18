<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * The backend entry point a report was started from.
 */
enum ReportSource: string
{
    case Toolbar = 'toolbar';
    case ContextMenu = 'contextMenu';
    case FormEngine = 'formEngine';
    case RecordList = 'recordList';
    case PageModule = 'pageModule';
    case FileList = 'fileList';

    public function getLabel(): string
    {
        return match ($this) {
            self::Toolbar => 'Backend toolbar',
            self::ContextMenu => 'Context menu',
            self::FormEngine => 'Record editing form',
            self::RecordList => 'Record list',
            self::PageModule => 'Page module',
            self::FileList => 'File list',
        };
    }
}

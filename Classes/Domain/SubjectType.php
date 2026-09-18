<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * The kind of TYPO3 object a report is about.
 */
enum SubjectType: string
{
    /** No specific object: the report is about the backend view the user was in */
    case Backend = 'backend';
    case Page = 'page';
    case Record = 'record';
    /** A file of a file storage (FAL), identified by its sys_file UID */
    case File = 'file';
    /** A folder of a file storage (FAL), identified by its combined identifier */
    case Folder = 'folder';
}

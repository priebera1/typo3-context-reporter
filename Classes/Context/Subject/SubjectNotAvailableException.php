<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

/**
 * The requested page or record does not exist or the backend user must not see it.
 * Both cases are deliberately indistinguishable for the client.
 *
 * @internal
 */
final class SubjectNotAvailableException extends \RuntimeException {}

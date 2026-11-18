<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A backend user without session or database: `writeUC()` only counts.
 */
final class BackendUserStub extends BackendUserAuthentication
{
    public int $ucWrites = 0;

    public static function create(int $uid, bool $admin): self
    {
        $user = new self();
        $user->user = ['uid' => $uid, 'admin' => $admin ? 1 : 0, 'username' => 'user' . $uid];
        $user->uc = [];
        return $user;
    }

    public function writeUC(): void
    {
        $this->ucWrites++;
    }
}

<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Subject;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\ContextReporter\Context\Subject\FileAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;

/**
 * File access fails closed: a resource TYPO3 cannot resolve, because a
 * storage, driver or index entry is unusable, is not accessible, whatever
 * the lookup throws. Callers such as the context menu provider must never
 * fail because of it.
 */
final class FileAccessTest extends TestCase
{
    #[Test]
    public function resourcesWhoseLookupFailsAreNotAccessible(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $error = new \Error('Call to a member function checkActionPermission() on null');
        $resourceFactory->method('getObjectFromCombinedIdentifier')->willThrowException($error);
        $resourceFactory->method('getFileObject')->willThrowException($error);
        $resourceFactory->method('getFolderObjectFromCombinedIdentifier')->willThrowException(new \TypeError('Unusable storage'));
        $fileAccess = new FileAccess($resourceFactory, $this->createMock(ConnectionPool::class), $this->createMock(FileNameValidator::class));
        $backendUser = $this->createMock(BackendUserAuthentication::class);

        self::assertNull($fileAccess->findResource('1:/user_upload/logo.png', $backendUser));
        self::assertNull($fileAccess->findFile(1, $backendUser));
        self::assertNull($fileAccess->findIndexedFile(1, $backendUser));
        self::assertNull($fileAccess->findFolder('1:/user_upload/', $backendUser));
    }
}

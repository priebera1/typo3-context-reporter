<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\EnvironmentReference;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EnvironmentReferenceTest extends UnitTestCase
{
    #[Test]
    public function variableNameIsExtractedFromAReference(): void
    {
        self::assertSame('CONTEXT_REPORTER_SECRET', EnvironmentReference::getVariableName('%env(CONTEXT_REPORTER_SECRET)%'));
        self::assertTrue(EnvironmentReference::isReference('%env(_A1)%'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainValues(): array
    {
        return [
            'empty' => [''],
            'plain secret' => ['s3cr3t'],
            'embedded reference' => ['Bearer %env(TOKEN)%'],
            'trailing newline' => ["%env(TOKEN)%\n"],
            'invalid name' => ['%env(1TOKEN)%'],
            'missing percent' => ['env(TOKEN)'],
        ];
    }

    #[Test]
    #[DataProvider('plainValues')]
    public function plainValuesAreNoReferences(string $value): void
    {
        self::assertFalse(EnvironmentReference::isReference($value));
        self::assertNull(EnvironmentReference::getVariableName($value));
    }
}

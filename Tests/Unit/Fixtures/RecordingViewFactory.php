<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;

/**
 * Stands in for Fluid in unit tests: records what a view was given and renders
 * a fixed text (or the given size, to test size budgets).
 */
final class RecordingViewFactory implements ViewFactoryInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $rendered = [];

    /**
     * @param \Closure(array<string, mixed>): string|null $output
     */
    public function __construct(private readonly ?\Closure $output = null) {}

    public function create(ViewFactoryData $data): ViewInterface
    {
        $factory = $this;
        return new class ($factory, $this->output) implements ViewInterface {
            /**
             * @var array<string, mixed>
             */
            private array $variables = [];

            /**
             * @param \Closure(array<string, mixed>): string|null $output
             */
            public function __construct(private readonly RecordingViewFactory $factory, private readonly ?\Closure $output) {}

            public function assign(string $key, mixed $value): self
            {
                $this->variables[$key] = $value;
                return $this;
            }

            public function assignMultiple(array $values): self
            {
                $this->variables = $values + $this->variables;
                return $this;
            }

            public function render(string $templateFileName = ''): string
            {
                $this->factory->rendered[] = $this->variables;
                return $this->output !== null ? ($this->output)($this->variables) : '<p>HTML part</p>';
            }
        };
    }
}

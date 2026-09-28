<?php

declare(strict_types=1);

namespace Kanvas\Approvals\DataTransferObject;

/**
 * One rung of a policy's approval chain. A single-approver policy is a one-element chain, so the
 * chain machinery is the engine rather than an extra feature layered on top.
 */
class ApprovalStep
{
    public function __construct(
        public readonly int $step,
        public readonly string $resolver,
        public readonly array $config = [],
        public readonly int $requiredApprovals = 1,
        public readonly ?array $when = null,
    ) {
    }

    public static function fromArray(array $definition, int $fallbackStep): self
    {
        return new self(
            step: (int) ($definition['step'] ?? $fallbackStep),
            resolver: (string) ($definition['resolver'] ?? ''),
            config: (array) ($definition['config'] ?? []),
            requiredApprovals: max(1, (int) ($definition['required_approvals'] ?? 1)),
            when: isset($definition['when']) ? (array) $definition['when'] : null,
        );
    }
}

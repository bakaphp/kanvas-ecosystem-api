<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use BackedEnum;
use Closure;
use RuntimeException;

/**
 * Shared scaffolding for the per-sub-ledger state machines (Invoice / Bill / Expense / Quote /
 * SalesReceipt). Each subclass declares its own `ALLOWED` graph + the entity label + an exception
 * factory; this base implements `canTransition` + `guardTransition` + `formatAllowed` once.
 *
 * Why an abstract base instead of N copies:
 *   - Removes ~250 LOC of duplication across 5 state machines.
 *   - Eliminates drift — e.g. one machine's "(none — terminal)" vs another's "(none — terminal state)",
 *     or one forgetting to support `from === to` idempotency. Behavior is now centralized.
 *   - New sub-ledger (vendor credits, journal-only, etc.) plugs in by declaring 3 short methods.
 *
 * Subclasses still expose a typed `assertTransition(Entity, Enum)` method — that's the public API
 * Actions consume — and delegate to `guardTransition()` for the actual gate. Keeping the typed
 * method preserves IDE autocomplete and static analysis at the call site.
 */
abstract class AbstractDocumentStateMachineService
{
    /**
     * The per-status allowed-target graph. Key = current status string (BackedEnum value); value =
     * list of BackedEnum targets reachable from there. Terminal states map to `[]`.
     *
     * @return array<string, list<BackedEnum>>
     */
    abstract protected function allowedTransitions(): array;

    /**
     * Human label for the entity in exception messages — e.g. "Invoice", "Bill", "Expense".
     */
    abstract protected function entityLabel(): string;

    /**
     * Factory that wraps the formatted message in the subclass's domain-specific exception type.
     */
    abstract protected function transitionExceptionFactory(): Closure;

    public function canTransition(BackedEnum $from, BackedEnum $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = $this->allowedTransitions()[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    /**
     * Throw the subclass's exception when the transition isn't allowed. Subclasses' typed
     * `assertTransition` methods funnel through here so the message format stays uniform.
     */
    protected function guardTransition(
        int $entityId,
        BackedEnum $current,
        BackedEnum $target,
        string $fieldName = 'status',
    ): void {
        if ($this->canTransition($current, $target)) {
            return;
        }

        $factory = $this->transitionExceptionFactory();
        $message = "{$this->entityLabel()} {$entityId} cannot transition {$fieldName} "
            . "from '{$current->value}' to '{$target->value}'. "
            . 'Allowed targets: ' . $this->formatAllowed($current) . '.';

        throw $factory($message);
    }

    protected function formatAllowed(BackedEnum $from): string
    {
        $allowed = $this->allowedTransitions()[$from->value] ?? [];
        if ($allowed === []) {
            return '(none — terminal state)';
        }

        return implode(', ', array_map(fn (BackedEnum $e) => $e->value, $allowed));
    }

    /**
     * Defensive: the factory the subclass returns MUST yield a Throwable. Catch the common foot-gun
     * of returning a string by raising a clear error instead of `Class 'Closure' not found`-style
     * confusion.
     */
    protected function ensureThrowable(mixed $value): RuntimeException
    {
        if ($value instanceof RuntimeException) {
            return $value;
        }

        return new RuntimeException(
            'State-machine exception factory returned non-Throwable: ' . get_debug_type($value)
        );
    }
}

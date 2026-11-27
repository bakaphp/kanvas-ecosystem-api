<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;

trait LocksDocumentRow
{
    /**
     * Runs the work in a transaction with the document's row locked and the model re-read. A guard inside then
     * checks the document as it is now, not the copy the caller loaded, and two requests on the same document
     * cannot both pass it.
     *
     * The re-read is the locking read itself: a plain refresh() answers from the transaction's snapshot, which can
     * be older than the lock. For the same reason, lock every row a guard depends on before reading anything else.
     *
     * @template TResult
     *
     * @param Closure(): TResult $work
     *
     * @return TResult
     */
    private function withRowLocked(Model $document, Closure $work): mixed
    {
        return DB::connection($document->getConnectionName())->transaction(
            function () use ($document, $work): mixed {
                $current = $document->newQueryWithoutScopes()
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    throw new ValidationException(
                        class_basename($document) . " {$document->getKey()} no longer exists."
                    );
                }

                $document->setRawAttributes($current->getAttributes(), true);
                $document->unsetRelations();

                return $work();
            }
        );
    }

    /**
     * Locks the row and says whether its status is still the one on the caller's copy. For an action that has to
     * keep the caller's unsaved changes, which withRowLocked() would re-read over. Call it inside a transaction.
     */
    private function lockedStatusIsUnchanged(Model $document, string $column = 'document_status'): bool
    {
        $current = $document->newQueryWithoutScopes()
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->value($column);

        return $current === $document->getAttribute($column);
    }
}

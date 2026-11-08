<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Users\Models\Users;

/**
 * Stores the bytes of one side of an attempt (what went on the wire, what came back) as a Filesystem file.
 * Throws on any failure: the caller decides whether the attempt may go on without it, and a request artifact
 * is a precondition of sending.
 */
class FiscalArtifactService
{
    /**
     * @return array{file_id: int, sha256: string}
     */
    public function store(FiscalDocument $fiscal, string $contents, string $fileName): array
    {
        $entity = $fiscal->entity;

        if (! $entity instanceof Invoice && ! $entity instanceof Bill && ! $entity instanceof Expense) {
            throw new ValidationException("Fiscal document {$fiscal->uuid} has no Scribe document to own its artifacts.");
        }

        $user = $entity->user ?? $entity->company?->user;

        if (! $user instanceof Users) {
            throw new ValidationException("Fiscal document {$fiscal->uuid} has no user to own its artifacts.");
        }

        $file = new FilesystemServices($entity->app, $entity->company)->uploadContents($contents, $fileName, $user);

        return ['file_id' => (int) $file->getKey(), 'sha256' => hash('sha256', $contents)];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentRelation;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Writes the fiscal row for a number just allocated (or a document just received) and makes it the entity's
 * current one. Runs inside the caller's accounting transaction, so a rolled-back issue leaves no row behind.
 *
 * The entity row is locked first: two issues of the same document serialize here, and the unique
 * `current_key` refuses a second current row should anything slip past.
 */
class CreateFiscalDocumentAction
{
    /**
     * @param array<string, mixed> $attributes regime-built (FiscalRegimeInterface::issuedAttributes(), or a regime's received row)
     */
    public function __construct(
        public readonly Invoice|Bill|Expense $entity,
        public readonly FiscalDirectionEnum $direction,
        public readonly array $attributes,
        public readonly ?EcfReference $reference = null,
        public readonly ?FiscalDocument $referencedDocument = null,
    ) {
    }

    public function execute(): FiscalDocument
    {
        return DB::connection('accounting')->transaction(function (): FiscalDocument {
            self::lockEntity($this->entity);

            $currentKey = FiscalDocument::currentKeyFor($this->entity, $this->direction, (string) $this->attributes['environment']);

            FiscalDocument::query()->where('current_key', $currentKey)->update(['current_key' => null]);

            $document = new FiscalDocument([
                ...$this->attributes,
                'apps_id' => $this->entity->apps_id,
                'companies_id' => $this->entity->companies_id,
                'entity_type' => $this->entity->getMorphClass(),
                'entity_id' => $this->entity->getKey(),
                'direction' => $this->direction,
                'current_key' => $currentKey,
            ]);
            $document->save();

            if ($this->reference !== null) {
                FiscalDocumentRelation::create([
                    'apps_id' => $document->apps_id,
                    'companies_id' => $document->companies_id,
                    'fiscal_document_id' => $document->getKey(),
                    'related_fiscal_document_id' => $this->referencedDocument?->getKey(),
                    'related_reference' => $this->reference->encf,
                    'related_issued_at' => $this->reference->issueDate,
                    'relation_type' => FiscalDocumentRelation::MODIFIES,
                    'regime_code' => (string) $this->reference->modificationCode,
                ]);
            }

            return $document;
        });
    }

    public static function lockEntity(Model $entity): void
    {
        $entity->newQueryWithoutScopes()->whereKey($entity->getKey())->lockForUpdate()->first();
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Approvals;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * What the approver of a purchase e-CF signs, frozen into the request. The handler re-hashes the document
 * and refuses to issue one that changed since, like InvoiceApprovalPayload does for sales.
 */
final class PurchaseEcfApprovalPayload
{
    public const string HASH_KEY = 'draft_hash';

    /**
     * @return array<string, mixed>
     */
    public static function for(Bill|Expense $document): array
    {
        $frozen = self::frozenFields($document);

        return [
            ...$frozen,
            'vendor' => $document->vendor_display_name,
            self::HASH_KEY => self::hash($frozen),
        ];
    }

    public static function currentHash(Bill|Expense $document): string
    {
        return self::hash(self::frozenFields($document));
    }

    /**
     * @return array<string, mixed>
     */
    private static function frozenFields(Bill|Expense $document): array
    {
        $compliance = EcfCompliance::read($document);

        return [
            'document' => strtolower(class_basename($document)),
            'ecf_type' => $compliance['type'] ?? null,
            'vendor_organization_id' => $document->vendor_organization_id,
            'vendor_tax_id' => $document->vendor_tax_id,
            'currency' => $document->currency,
            'tax_native' => round((float) $document->tax_native, 4),
            'total_native' => round((float) $document->total_native, 4),
            'line_count' => $document->lines()->count(),
            'withholding' => $compliance['withholding'] ?? null,
            'foreign_payment' => $compliance['foreign_payment'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $frozen
     */
    private static function hash(array $frozen): string
    {
        return hash('sha256', (string) json_encode($frozen));
    }
}

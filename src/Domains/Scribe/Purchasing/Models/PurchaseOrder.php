<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Models;

use Baka\Casts\Json;
use Baka\Observers\ClearsLightHouseCacheObserver;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Contracts\DescribesApprovalDetailsInterface;
use Kanvas\Approvals\Contracts\ReactsToApprovalExpiryInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Override;

/**
 * A purchase order, for AP-bill matching. One mirrored from a source ERP (source other than kanvas) is
 * read-only reference data; one the company issues itself (source kanvas) has a document_status and its own
 * lifecycle. Neither posts to the ledger.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $order_type
 * @property string $order_number
 * @property int|null $vendor_organization_id
 * @property string|null $vendor_code
 * @property string|null $status
 * @property PurchaseOrderStatusEnum|null $document_status
 * @property Carbon|null $order_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property string|null $currency
 * @property string|null $notes
 * @property string $order_total
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property bool $is_deleted
 */
#[ObservedBy([ClearsLightHouseCacheObserver::class])]
class PurchaseOrder extends BaseModel implements DescribesApprovalDetailsInterface, ReactsToApprovalExpiryInterface
{
    use HasApprovals;
    use HasLightHouseCache;
    use UuidTrait;

    protected $table = 'purchase_orders';
    protected $guarded = [];

    protected $casts = [
        'document_status' => PurchaseOrderStatusEnum::class,
        'order_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'order_total' => 'decimal:4',
        'is_deleted' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public const string SOURCE_KANVAS = 'kanvas';

    public function isIssuedByCompany(): bool
    {
        return $this->source === self::SOURCE_KANVAS && $this->document_status !== null;
    }

    /**
     * @return array{party: ?string, amount: ?float, currency: ?string, due_date: ?string}
     */
    #[Override]
    public function approvalDetails(): array
    {
        return [
            'party' => $this->vendor?->name,
            'amount' => (float) $this->order_total,
            'currency' => $this->currency,
            'due_date' => $this->expected_date?->toDateString(),
        ];
    }

    /**
     * An order whose approval expired with no decision goes back to draft, with a note saying why.
     */
    #[Override]
    public function approvalExpired(ApprovalRequest $request): void
    {
        if ($this->document_status !== PurchaseOrderStatusEnum::PENDING_APPROVAL) {
            return;
        }

        $this->document_status = PurchaseOrderStatusEnum::DRAFT;
        $this->notes = trim(
            ($this->notes ?? '') . "\nApproval request #{$request->getId()} expired with no decision; back in draft."
        );
        $this->saveOrFail();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id', 'id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class, 'purchase_order_id', 'id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'vendor_organization_id', 'id');
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'ScribePurchaseOrder';
    }
}

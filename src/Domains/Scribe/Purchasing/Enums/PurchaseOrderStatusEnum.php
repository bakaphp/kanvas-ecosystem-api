<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Enums;

/**
 * Lifecycle of a purchase order the company issues. Orders mirrored from an ERP have no status here.
 */
enum PurchaseOrderStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case SENT = 'sent';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';
}

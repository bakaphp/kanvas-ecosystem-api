<?php

declare(strict_types=1);

namespace App\GraphQL\Approvals\Types;

use App\GraphQL\Types\MappedUnionTypeResolver;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Social\Messages\Models\Message;

/**
 * Bill/Invoice/Expense/PurchaseOrder GraphQL types are prefixed (ScribeBill, ScribeInvoice, ScribeExpense,
 * ScribePurchaseOrder) to avoid colliding with other domains' own concepts.
 */
class ApprovableEntityTypeResolver extends MappedUnionTypeResolver
{
    protected const array MODEL_TO_TYPE_NAME = [
        People::class => 'People',
        Lead::class => 'Lead',
        Message::class => 'Message',
        Bill::class => 'ScribeBill',
        Invoice::class => 'ScribeInvoice',
        Expense::class => 'ScribeExpense',
        PurchaseOrder::class => 'ScribePurchaseOrder',
        Task::class => 'NervousSystemTask',
        Organization::class => 'Organization',
    ];
}

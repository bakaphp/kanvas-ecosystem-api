<?php

declare(strict_types=1);

namespace App\GraphQL\Approvals\Types;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Social\Messages\Models\Message;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Lighthouse's default union type resolver matches on the resolved value's class_basename, which only
 * works when the model's short class name equals its GraphQL type name. That holds for People and
 * Message, but not for Bill/Invoice/Expense — their GraphQL types are prefixed (ScribeBill,
 * ScribeInvoice, ScribeExpense) to avoid colliding with other domains' own Bill/Invoice/Expense
 * concepts, so they need an explicit mapping instead.
 */
class ApprovableEntityTypeResolver
{
    private const array MODEL_TO_TYPE_NAME = [
        People::class => 'People',
        Lead::class => 'Lead',
        Message::class => 'Message',
        Bill::class => 'ScribeBill',
        Invoice::class => 'ScribeInvoice',
        Expense::class => 'ScribeExpense',
    ];

    public function __construct(
        private readonly TypeRegistry $typeRegistry,
    ) {
    }

    public function __invoke(mixed $root, GraphQLContext $context, ResolveInfo $resolveInfo): Type
    {
        $typeName = self::MODEL_TO_TYPE_NAME[$root::class] ?? class_basename($root);

        return $this->typeRegistry->get($typeName);
    }
}

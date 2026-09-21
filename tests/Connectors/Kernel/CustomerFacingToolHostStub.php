<?php

declare(strict_types=1);

namespace Tests\Connectors\Kernel;

use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;

/**
 * The same toolset builder, on an agent whose counterparty is a prospect.
 */
final class CustomerFacingToolHostStub extends KernelToolHostStub implements ConversesWithCustomer
{
}

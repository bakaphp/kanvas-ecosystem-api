<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Kanvas\Scribe\Invoices\Approvals\IssueInvoiceHandler;

/**
 * Kept so approval_policies rows already naming this class keep resolving. The issue itself is
 * Scribe's IssueInvoiceHandler; the Acumatica push runs from the invoice status-transition workflow.
 */
class IssueAndPushInvoiceHandler extends IssueInvoiceHandler
{
}

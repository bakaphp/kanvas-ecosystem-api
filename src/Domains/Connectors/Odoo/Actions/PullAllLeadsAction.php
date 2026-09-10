<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Odoo\Client;

/**
 * Bulk-pulls every `crm.lead` with `type = 'lead'` (Odoo's equivalent of Salesforce's Lead).
 * `type = 'opportunity'` rows are excluded — see `PullLeadAction`'s docblock for why Opportunity
 * mapping is out of scope for this pass.
 */
class PullAllLeadsAction
{
    private const array FIELDS = ['id', 'name', 'contact_name', 'email_from', 'phone', 'partner_name', 'stage_id', 'description'];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        return Client::getInstance($this->app, $this->company)
            ->searchReadAll('crm.lead', [['type', '=', 'lead']], self::FIELDS);
    }
}

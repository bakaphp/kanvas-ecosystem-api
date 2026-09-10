<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Odoo\Client;

/**
 * Bulk-pulls every `res.partner` with `is_company = true` (Odoo's equivalent of Salesforce's
 * Account) from the Odoo instance. Mirrors `PullAllOrganizationsAction` from the Salesforce
 * connector — collects every raw record first, no per-record processing here.
 */
class PullAllOrganizationsAction
{
    private const array FIELDS = ['id', 'name', 'phone'];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        return Client::getInstance($this->app, $this->company)
            ->searchReadAll('res.partner', [['is_company', '=', true]], self::FIELDS);
    }
}

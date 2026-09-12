<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Odoo\Client;

/**
 * Odoo has no Account object: a `res.partner` with `is_company = true` is the organization,
 * the same model with `is_company = false` is a person (see `PullAllPeopleAction`).
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

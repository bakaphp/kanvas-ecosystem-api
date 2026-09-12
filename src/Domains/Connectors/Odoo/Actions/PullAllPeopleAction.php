<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Odoo\Client;

class PullAllPeopleAction
{
    private const array FIELDS = ['id', 'name', 'email', 'phone', 'parent_id'];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        return Client::getInstance($this->app, $this->company)
            ->searchReadAll('res.partner', [['is_company', '=', false]], self::FIELDS);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Odoo\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Odoo\Client;
use Kanvas\Connectors\Odoo\DataTransferObject\OdooPartner;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

class PushPeopleAction
{
    use UpsertsByExternalId;

    public function __construct(
        protected People $people,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $people = People::where('id', $this->people->id)->lockForUpdate()->firstOrFail();
            $company = $people->company;

            $organization = $people->organizations()->first();
            if ($organization !== null && ! $organization->get(CustomFieldEnum::ODOO_ACCOUNT_ID->value)) {
                new PushOrganizationAction($organization)->execute();
                $organization->refresh();
            }

            $data = OdooPartner::fromPeople($people, $organization)->toArray();

            $client = Client::getInstance($people->app, $company);

            return $this->upsertByExternalId(
                $client,
                'res.partner',
                $people,
                CustomFieldEnum::ODOO_CONTACT_ID,
                $data,
            );
        });
    }
}

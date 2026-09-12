<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Odoo\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Odoo\Client;
use Kanvas\Connectors\Odoo\DataTransferObject\OdooPartner;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

class PushOrganizationAction
{
    use UpsertsByExternalId;

    public function __construct(
        protected Organization $organization,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $organization = Organization::where('id', $this->organization->id)->lockForUpdate()->firstOrFail();
            $company = $organization->company;
            $data = OdooPartner::fromOrganization($organization)->toArray();

            $client = Client::getInstance($organization->app, $company);

            return $this->upsertByExternalId(
                $client,
                'res.partner',
                $organization,
                CustomFieldEnum::ODOO_ACCOUNT_ID,
                $data,
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\DataTransferObject\OrganizationType as OrganizationTypeData;
use Kanvas\Guild\Organizations\Models\OrganizationType;

class CreateOrganizationTypeAction
{
    public function __construct(
        protected readonly OrganizationTypeData $data,
    ) {
    }

    public function execute(): OrganizationType
    {
        $organizationType = new OrganizationType();
        $organizationType->apps_id = $this->data->apps->getId();
        $organizationType->companies_id = $this->data->companies->getId();
        $organizationType->users_id = $this->data->user->getId();
        $organizationType->name = $this->data->name;
        $organizationType->description = $this->data->description;
        $organizationType->is_active = $this->data->is_active;
        $organizationType->is_default = $this->data->is_default;
        $organizationType->config = $this->data->config;
        $organizationType->saveOrFail();

        return $organizationType;
    }
}

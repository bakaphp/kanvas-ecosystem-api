<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\DataTransferObject\OrganizationType as OrganizationTypeData;
use Kanvas\Guild\Organizations\Models\OrganizationType;

class UpdateOrganizationTypeAction
{
    public function __construct(
        protected readonly OrganizationType $organizationType,
        protected readonly OrganizationTypeData $data,
    ) {
    }

    public function execute(): OrganizationType
    {
        $this->organizationType->name = $this->data->name;
        $this->organizationType->description = $this->data->description;
        $this->organizationType->is_active = $this->data->is_active;
        $this->organizationType->is_default = $this->data->is_default;
        $this->organizationType->config = $this->data->config;
        $this->organizationType->saveOrFail();

        return $this->organizationType;
    }
}

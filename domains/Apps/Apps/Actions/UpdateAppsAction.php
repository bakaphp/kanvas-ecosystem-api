<?php

declare(strict_types=1);

namespace Kanvas\Apps\Apps\Actions;

use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Apps\Apps\DataTransferObject\AppsPutData;

class UpdateAppsAction
{
    /**
     * Construct function
     *
     * @param AppsPutData $data
     */
    public function __construct(
        protected AppsPutData $data
    ) {
    }

    /**
     * Invoke function
     *
     * @param int $id
     *
     * @return Apps
     */
    public function execute(int $id): Apps
    {
        $app = Apps::findOrFail($id);
        $app->fill([
            'name' => $this->data->name,
            'url' => $this->data->url,
            'description' => $this->data->description,
            'domain' => $this->data->domain,
            'is_actived' => $this->data->is_actived,
            'ecosystem_auth' => $this->data->ecosystem_auth,
            'payments_active' => $this->data->payments_active,
            'is_public' => $this->data->is_public,
            'domain_based' => $this->data->domain_based
        ]);
        return $app;
    }
}

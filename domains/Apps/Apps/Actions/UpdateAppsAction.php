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
        $app->name = $this->data->name;
        $app->url = $this->data->url;
        $app->description = $this->data->description;
        $app->domain = $this->data->domain;
        $app->is_actived = $this->data->is_actived;
        $app->ecosystem_auth = $this->data->ecosystem_auth;
        $app->payments_active = $this->data->payments_active;
        $app->is_public = $this->data->is_public;
        $app->domain_based = $this->data->domain_based;
        $app->update();

        return $app;
    }
}

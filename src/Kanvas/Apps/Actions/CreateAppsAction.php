<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Models\Apps;

class CreateAppsAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected AppsPostData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param AppsPostData $data
     *
     * @return Apps
     */
    public function execute() : Apps
    {
        $app = new Apps();
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
        $app->saveOrFail();
        return $app;
    }
}

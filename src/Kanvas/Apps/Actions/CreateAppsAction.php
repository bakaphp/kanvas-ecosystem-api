<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Throwable;

class CreateAppsAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected AppInput $data,
        protected Users $user
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Apps
     *
     * @throws Throwable
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

        $app->associateUser($this->user, $this->data->is_actived);

        return $app;
    }
}

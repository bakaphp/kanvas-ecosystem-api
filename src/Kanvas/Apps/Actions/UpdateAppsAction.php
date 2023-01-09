<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class UpdateAppsAction
{
    /**
     * Construct function.
     *
     * @param AppInput $data
     */
    public function __construct(
        protected AppInput $data,
        protected Users $user
    ) {
    }

    /**
     * Invoke function.
     *
     * @param string $id
     *
     * @return Apps
     */
    public function execute(string $id) : Apps
    {
        /**
         * @todo only super admins can modify apps
         */
        $app = AppsRepository::findFirstByKey($id);
        UsersRepository::userOwnsThisApp($this->user, $app);

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

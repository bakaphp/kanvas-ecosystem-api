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
        $app->updateOrFail($this->data->spitFilledAsArray());

        return $app;
    }
}

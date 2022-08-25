<?php

declare(strict_types=1);

namespace Kanvas\Apps\Apps\Actions;

use Kanvas\Apps\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Apps\Models\Apps;

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
        $app->fill($this->data->spitFilledAsArray());
        $app->saveOrFail();
        return $app;
    }
}

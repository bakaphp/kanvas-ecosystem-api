<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Workflows\Activities;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SavePeopleToIPlusAction;
use Kanvas\Guild\Customers\Models\People;
use Workflow\Activity;

class SyncPeopleWithIPlusActivities extends Activity
{
    use KanvasJobsTrait;

    public function execute(People $people, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $createPeopleAction = new SavePeopleToIPlusAction($people);
        $response = $createPeopleAction->execute();

        return [
            'status' => 'success',
            'message' => 'People synced with IPlus',
            'response' => $response,
        ];
    }
}

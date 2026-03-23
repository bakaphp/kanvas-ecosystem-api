<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Models\ScheduleHistory;

class CreateScheduleHistoryAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected Model $resource,
        protected string $action,
        protected ?array $payload = null,
    ) {
    }

    public function execute(): ScheduleHistory
    {
        return ScheduleHistory::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'resources_id' => $this->resource->getId(),
            'resources_type' => $this->resource->getMorphClass(),
            'action' => $this->action,
            'payload' => $this->payload,
        ]);
    }
}

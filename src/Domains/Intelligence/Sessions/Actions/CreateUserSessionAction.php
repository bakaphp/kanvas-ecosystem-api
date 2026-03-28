<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;

class CreateUserSessionAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly Users $user,
        protected readonly Apps $app,
        protected readonly Companies $company,
    ) {
    }

    public function execute(): Session
    {
        return Session::create([
            'uuid' => Str::uuid()->toString(),
            'apps_id' => $this->app->getId(),
            'agents_id' => $this->agent->getId(),
            'companies_id' => $this->company->getId(),
            'channel_id' => null,
            'entity_namespace' => Users::class,
            'entity_id' => $this->user->getId(),
            'content' => '',
            'user' => [
                'name' => $this->user->firstname . ' ' . $this->user->lastname,
                'id' => $this->user->getId(),
                'email' => $this->user->email,
            ],
        ]);
    }
}

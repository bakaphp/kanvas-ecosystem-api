<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class CreateAppSettings
{
    protected Apps $app;
    protected string $name;
    protected string|array $value;

    /**
     * __construct
     *
     * @param  Users $user
     * @return void
     */
    public function __construct(Apps $app, string $name, string|array $value, ?Users $user = null)
    {
        $this->app = $app;
        $this->name = $name;
        $this->value = $value;
        $user = $user ?? auth()->user();
        UsersRepository::userOwnsThisApp($user, $app);
    }

    /**
     * execute
     */
    public function execute(): void
    {
        $this->app->set($this->name, $this->value);
    }
}

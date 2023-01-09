<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Silber\Bouncer\Database\Role as SilberRole;

class CreateRoleAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public string $name,
        public string $title,
        public ?Apps $app = null
    ) {
        if ($app === null) {
            $this->app = app(Apps::class);
        }
    }

    /**
     * execute.
     *
     * @return SilberRole
     */
    public function execute(?Companies $company = null) : SilberRole
    {
        Bouncer::scope()->to(RolesEnums::getKey($this->app, $company));

        $role = Bouncer::role()->firstOrCreate([
            'name' => $this->name,
            'title' => $this->title,
        ]);

        return $role;
    }
}

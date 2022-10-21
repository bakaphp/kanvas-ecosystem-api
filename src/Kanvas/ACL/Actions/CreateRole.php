<?php

declare(strict_types=1);
namespace Kanvas\ACL\Actions;

use Bouncer;
use Silber\Bouncer\Database\Role as SilberRole;

class CreateRole
{
    public function __construct(
        public string $name,
        public string $title,
    ) {
    }

    public function execute(): SilberRole
    {
        $role = Bouncer::role()->firstOrCreate([
            'name' => $this->name,
            'title' => $this->title,
        ]);
        return $role;
    }
}

<?php

declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Silber\Bouncer\Database\Role as SilberRole;

class CreateRole
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public string $name,
        public string $title,
    ) {
    }

    /**
     * execute
     *
     * @return SilberRole
     */
    public function execute(): SilberRole
    {
        $role = Bouncer::role()->firstOrCreate([
            'name' => $this->name,
            'title' => $this->title,
        ]);
        return $role;
    }
}

<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Actions;

use Bouncer;

class AllowAction
{
    public function __construct(
        public string $ability,
        public string $entity,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        Bouncer::allow($this->entity)->to($this->ability);
    }
}

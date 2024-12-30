<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class CheckListTaskItemTest extends TestCase
{
    public function testChangeCheckListStatus(): void
    {
        $lead = Lead::factory()->create();
        //$eng
        /**
         * @todo
         * - create action factory
         * - create company action factory
         * - create engagement message for one status
         * - move engagement checklist status
         * - create another engagement message for another status
         * - move engagement checklist status
         */
    }
}

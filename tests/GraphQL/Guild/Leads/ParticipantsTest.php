<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild\Leads;

use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Leads\Models\Leads;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class ParticipantsTest extends TestCase
{
    /**
     * testSave.
     *
     * @return void
     */
    public function testAddParticipants(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $people = Peoples::factory()->make([
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
        ]);

        print_r($people->toArray()); die();
    }

}

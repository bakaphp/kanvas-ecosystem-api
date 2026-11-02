<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PeoplePhoneLandlineTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testFlagsOnlyTheMatchingPhoneContact(): void
    {
        $people = $this->createPeople();
        $landline = $people->addPhone('+18095551234');
        $mobile = $people->addCellPhone('+18095559876');

        $updated = $people->setPhoneLandline('+18095551234', true);

        $this->assertSame(1, $updated);
        $this->assertTrue($landline->fresh()->isLandline());
        $this->assertNull($mobile->fresh()->isLandline());
        $this->assertSame(0, $landline->fresh()->is_opt_out);
    }

    public function testAMobileResultClearsAnEarlierLandlineFlag(): void
    {
        $people = $this->createPeople();
        $contact = $people->addCellPhone('+18095551234');

        $people->setPhoneLandline('+18095551234', true);
        $people->setPhoneLandline('8095551234', false);

        $this->assertFalse($contact->fresh()->isLandline());
    }

    private function createPeople(): People
    {
        $user = auth()->user();

        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
    }
}

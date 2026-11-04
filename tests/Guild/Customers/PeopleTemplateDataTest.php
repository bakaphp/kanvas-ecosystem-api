<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Templates\Support\PlainData;
use Tests\TestCase;

class PeopleTemplateDataTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    public function testTemplatesSeeOnlyTheAllowListedPersonFields(): void
    {
        $company = Companies::factory()->create();
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create(['firstname' => 'Adimedys', 'license_number' => 'SECRET-LICENCE']);
        $person->contacts()->delete();
        $person->addEmail('adimedys@example.com');
        $person->set('participant_id', 'PA40876');

        $data = PlainData::from($person);

        $this->assertSame('Adimedys', $data['firstname']);
        $this->assertSame('adimedys@example.com', $data['email']);
        $this->assertSame('PA40876', $data['custom_fields']['participant_id']);
        $this->assertArrayNotHasKey('license_number', $data);
        $this->assertArrayNotHasKey('google_contact_id', $data);
    }
}

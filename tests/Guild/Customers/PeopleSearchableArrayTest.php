<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

class PeopleSearchableArrayTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    /**
     * firstname/lastname/name are non-optional `string` fields in the Typesense schema, so a person
     * with no surname (lastname = null) failed the whole import with "Field `lastname` must be a
     * string" (Sentry KANVAS-ECOSYSTEM-628). The search doc must coerce them to strings.
     */
    public function testToSearchableArrayCoercesNullNamePartsToStrings(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create([
                'firstname' => 'Wayland',
                'middlename' => null,
                'lastname' => null,
            ]);

        $doc = $people->fresh()->toSearchableArray();

        $this->assertSame('', $doc['lastname'], 'null lastname must serialize as "" so Typesense accepts it');
        $this->assertIsString($doc['firstname']);
        $this->assertIsString($doc['middlename']);
        $this->assertIsString($doc['name']);
    }
}

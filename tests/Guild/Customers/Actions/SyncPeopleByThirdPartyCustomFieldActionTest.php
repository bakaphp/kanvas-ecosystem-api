<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Actions;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Users\Models\Users;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class SyncPeopleByThirdPartyCustomFieldActionTest extends TestCase
{
    use DatabaseTransactions;

    private const THIRD_PARTY_KEY = 'third_party_people_ref';

    public function testYieldsToConcurrentSyncOnLockTimeout(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $reference = 'vin-' . fake()->unique()->uuid();

        $existingPeople = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Persist the custom field the concurrent sync would have written, so the
        // fallback lookup can find the canonical people record.
        $existingPeople->set(self::THIRD_PARTY_KEY, $reference);

        $this->forceLockTimeout();

        $people = new SyncPeopleByThirdPartyCustomFieldAction(
            $this->peopleDto($app, $user, $company, $reference),
        )->execute();

        $this->assertSame($existingPeople->getId(), $people->getId());
    }

    public function testRethrowsLockTimeoutWhenNoPeopleExistsYet(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $reference = 'vin-' . fake()->unique()->uuid();

        $this->forceLockTimeout();

        $this->expectException(LockTimeoutException::class);

        new SyncPeopleByThirdPartyCustomFieldAction(
            $this->peopleDto($app, $user, $company, $reference),
        )->execute();
    }

    private function forceLockTimeout(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException());

        Cache::shouldReceive('lock')->andReturn($lock);
    }

    private function peopleDto(
        Apps $app,
        Users $user,
        Companies $company,
        string $reference
    ): PeopleData {
        return new PeopleData(
            app: $app,
            branch: $company->branch,
            user: $user,
            firstname: 'Concurrent',
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Sync',
            custom_fields: [self::THIRD_PARTY_KEY => $reference],
            runWorkflow: false,
        );
    }
}

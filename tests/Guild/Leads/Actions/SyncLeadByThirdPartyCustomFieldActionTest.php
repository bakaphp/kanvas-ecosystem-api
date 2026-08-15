<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Mockery;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class SyncLeadByThirdPartyCustomFieldActionTest extends TestCase
{
    use DatabaseTransactions;

    private const THIRD_PARTY_KEY = 'third_party_lead_ref';

    public function testYieldsToConcurrentSyncOnLockTimeout(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $reference = 'vin-' . fake()->unique()->uuid();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $existingLead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Persist the custom field the concurrent sync would have written, so the
        // fallback lookup can find the canonical lead.
        $existingLead->set(self::THIRD_PARTY_KEY, $reference);

        $this->forceLockTimeout();

        $lead = new SyncLeadByThirdPartyCustomFieldAction(
            $this->leadDto($app, $user, $company, $reference),
        )->execute();

        $this->assertSame($existingLead->getId(), $lead->getId());
    }

    public function testRethrowsLockTimeoutWhenNoLeadExistsYet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $reference = 'vin-' . fake()->unique()->uuid();

        $this->forceLockTimeout();

        $this->expectException(LockTimeoutException::class);

        new SyncLeadByThirdPartyCustomFieldAction(
            $this->leadDto($app, $user, $company, $reference),
        )->execute();
    }

    private function forceLockTimeout(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException());

        Cache::shouldReceive('lock')->andReturn($lock);
    }

    private function leadDto(Apps $app, $user, $company, string $reference): LeadData
    {
        $branch = $company->branch;

        return new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'Concurrent Sync',
            pipeline_stage_id: 0,
            people: new PeopleData(
                app: $app,
                branch: $branch,
                user: $user,
                firstname: 'Concurrent',
                contacts: Contact::collect([], DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: 'Sync',
                custom_fields: [self::THIRD_PARTY_KEY => $reference],
                runWorkflow: false,
            ),
            custom_fields: [self::THIRD_PARTY_KEY => $reference],
            runWorkflow: false,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Classification\Jobs\ClassifyPeopleJob;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-6VS: people from the app 1/2 era carry apps_id 0 or NULL, and the
 * classification hook reported a TypeError on every sync that touched one.
 */
final class PeopleClassificationMissingAppTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testAPersonWithoutAnAppIsSkippedWithoutReporting(): void
    {
        $user = auth()->user();

        /** @var People $people */
        $people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        DB::connection('crm')->table('peoples')->where('id', $people->getId())->update(['apps_id' => null]);
        $people->refresh();

        Exceptions::fake();
        Queue::fake();

        ClassifyPeopleJob::dispatchIfEnabled($people);

        Exceptions::assertNothingReported();
        Queue::assertNothingPushed();
    }
}

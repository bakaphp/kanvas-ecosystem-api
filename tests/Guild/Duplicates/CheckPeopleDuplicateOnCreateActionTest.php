<?php

declare(strict_types=1);

namespace Tests\Guild\Duplicates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\CheckPeopleDuplicateOnCreateAction;
use Kanvas\Guild\Duplicates\Jobs\CheckPeopleDuplicateJob;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Tests\TestCase;

class CheckPeopleDuplicateOnCreateActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_creating_a_people_dispatches_the_check_job(): void
    {
        $people = $this->seedPeople('Dispatch', 'Test ' . uniqid());

        Queue::assertPushed(CheckPeopleDuplicateJob::class, fn ($job) => $job->peopleId === $people->getId());
    }

    public function test_does_not_dispatch_on_update(): void
    {
        $people = $this->seedPeople('Original', 'Person ' . uniqid());

        Queue::assertPushed(CheckPeopleDuplicateJob::class, 1);

        $people->firstname = 'Updated';
        $people->save();

        Queue::assertPushed(CheckPeopleDuplicateJob::class, 1);
    }

    public function test_queues_a_pending_group_when_the_new_record_collides(): void
    {
        $lastname = 'Puello' . uniqid();
        $existing = $this->seedPeople('Arfenis', $lastname);
        $newPeople = $this->seedPeople('arfenis', strtolower($lastname));

        $created = new CheckPeopleDuplicateOnCreateAction($newPeople)->execute();

        $this->assertSame(1, $created);

        $group = DuplicateReviewGroup::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('entity_type', People::class)
            ->first();

        $this->assertNotNull($group);
        $this->assertEqualsCanonicalizing([(int) $existing->id, (int) $newPeople->id], $group->member_ids);
    }

    public function test_does_nothing_when_the_new_record_has_no_match(): void
    {
        $lonely = $this->seedPeople('Lonely', 'Person' . uniqid());

        $created = new CheckPeopleDuplicateOnCreateAction($lonely)->execute();

        $this->assertSame(0, $created);
        $this->assertSame(
            0,
            DuplicateReviewGroup::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('companies_id', $this->company->getId())
                ->count(),
        );
    }

    private function seedPeople(string $firstname, string $lastname): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'name' => $firstname . ' ' . $lastname,
        ]);
    }
}

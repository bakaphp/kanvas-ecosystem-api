<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Duplicates\Jobs\CheckPeopleDuplicateJob;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class CreatePeopleActionDuplicateCheckTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dispatches_duplicate_check_job_on_create(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Duplicate',
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Check ' . uniqid(),
            runWorkflow: false,
        );

        $people = new CreatePeopleAction($peopleDto)->execute();

        Queue::assertPushed(CheckPeopleDuplicateJob::class, fn ($job) => $job->peopleId === $people->getId());
    }

    public function test_does_not_dispatch_on_update(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Original',
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: 'Person ' . uniqid(),
            runWorkflow: false,
        );

        $people = new CreatePeopleAction($peopleDto)->execute();

        Queue::assertPushed(CheckPeopleDuplicateJob::class, 1);

        $updateDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Updated',
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $people->lastname,
            id: $people->getId(),
            runWorkflow: false,
        );

        new CreatePeopleAction($updateDto)->execute();

        Queue::assertPushed(CheckPeopleDuplicateJob::class, 1);
    }
}

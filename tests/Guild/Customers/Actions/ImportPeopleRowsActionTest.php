<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\ImportPeopleRowsAction;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class ImportPeopleRowsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testCreatesOnePersonPerDistinctRow(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $result = new ImportPeopleRowsAction($app, $branch, $user)->execute([
            [
                'firstname' => 'Alpha',
                'lastname' => 'Tester',
                'contacts' => [['value' => 'alpha.' . uniqid() . '@example.test']],
            ],
            [
                'firstname' => 'Bravo',
                'lastname' => 'Tester',
                'contacts' => [['value' => 'bravo.' . uniqid() . '@example.test']],
            ],
        ]);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(2, $result['people_ids']);
        $this->assertSame([], $result['errors']);
    }

    public function testDedupsAnExistingContactIntoAnUpdateInsteadOfADuplicate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $company = $user->getCurrentCompany();
        $email = 'charlie.' . uniqid() . '@example.test';

        $existing = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['firstname' => 'Charlie', 'lastname' => 'Original']);
        $existing->addEmail($email);

        $result = new ImportPeopleRowsAction($app, $branch, $user)->execute([
            [
                'firstname' => 'Charlie',
                'lastname' => 'Updated',
                'contacts' => [['value' => $email]],
            ],
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([$existing->getId()], $result['people_ids']);
        $this->assertSame('Updated', $existing->refresh()->lastname);
    }

    public function testOneBadRowDoesNotAbortTheRestOfTheBatch(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $result = new ImportPeopleRowsAction($app, $branch, $user)->execute([
            ['lastname' => 'NoFirstname'],
            [
                'firstname' => 'Delta',
                'lastname' => 'Tester',
                'contacts' => [['value' => 'delta.' . uniqid() . '@example.test']],
            ],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['errors']);
        $this->assertCount(1, $result['people_ids']);
    }

    public function testAfterRowProcessedCallbackRunsPerSuccessfulRow(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $seen = [];

        new ImportPeopleRowsAction($app, $branch, $user)->execute(
            [[
                'firstname' => 'Echo',
                'lastname' => 'Tester',
                'contacts' => [['value' => 'echo.' . uniqid() . '@example.test']],
            ]],
            function (People $people, array $row) use (&$seen): void {
                $seen[] = [$people->getId(), $row['firstname']];
            },
        );

        $this->assertCount(1, $seen);
        $this->assertSame('Echo', $seen[0][1]);
    }
}

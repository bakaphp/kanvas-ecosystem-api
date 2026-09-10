<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Jobs\ProcessZohoDealWebhookJob;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use ReflectionMethod;
use Tests\TestCase;

final class ProcessZohoDealWebhookJobFindPeopleTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessZohoDealWebhookJob::class],
            ['name' => 'ProcessZohoDealWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($user->getCurrentCompany()->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testMatchesThePersonHoldingTheEmailContact(): void
    {
        $email = 'zoho-deal-' . fake()->unique()->uuid . '@example.com';
        $people = $this->makePeopleWithContact($email, ContactTypeEnum::EMAIL->value);

        $this->assertSame(
            $people->getId(),
            $this->findPeople(['Email' => $email])?->getId(),
        );
    }

    public function testFallsBackToPrimaryEmailKey(): void
    {
        $email = 'zoho-deal-' . fake()->unique()->uuid . '@example.com';
        $people = $this->makePeopleWithContact($email, ContactTypeEnum::EMAIL->value);

        $this->assertSame(
            $people->getId(),
            $this->findPeople(['Primary_Email' => $email])?->getId(),
        );
    }

    /**
     * The lookup used to query the contacts relation on `value` alone, so a person whose PHONE
     * row happened to hold the address was returned as an email match.
     */
    public function testIgnoresTheAddressStoredUnderANonEmailContactType(): void
    {
        $email = 'zoho-deal-' . fake()->unique()->uuid . '@example.com';
        $this->makePeopleWithContact($email, ContactTypeEnum::PHONE->value);

        $this->assertNull($this->findPeople(['Email' => $email]));
    }

    /**
     * Contacts were matched without checking `is_deleted`, so a removed address kept resolving
     * to the person it had been detached from.
     */
    public function testIgnoresASoftDeletedEmailContact(): void
    {
        $email = 'zoho-deal-' . fake()->unique()->uuid . '@example.com';
        $people = $this->makePeopleWithContact($email, ContactTypeEnum::EMAIL->value);
        $people->contacts()->where('value', $email)->update(['is_deleted' => 1]);

        $this->assertNull($this->findPeople(['Email' => $email]));
    }

    public function testDoesNotReachIntoAnotherCompany(): void
    {
        $email = 'zoho-deal-' . fake()->unique()->uuid . '@example.com';
        $otherCompany = Companies::factory()->create(['users_id' => auth()->user()->getId()]);
        $this->makePeopleWithContact($email, ContactTypeEnum::EMAIL->value, $otherCompany);

        $this->assertNull($this->findPeople(['Email' => $email]));
    }

    public function testReturnsNullWhenTheDealCarriesNoEmail(): void
    {
        $this->assertNull($this->findPeople([]));
    }

    private function makePeopleWithContact(
        string $value,
        int $contactTypeId,
        ?Companies $company = null
    ): People {
        $user = auth()->user();
        $company ??= $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($this->receiver->app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        Contact::factory()->create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $contactTypeId,
            'value' => $value,
        ]);

        return $people;
    }

    private function findPeople(array $zohoDealInfo): ?People
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [],
        );

        $job = new ProcessZohoDealWebhookJob(
            new ProcessWebhookAttemptAction($this->receiver, $request)->execute(),
        );

        $findPeople = new ReflectionMethod($job, 'findPeople');
        $findPeople->setAccessible(true);

        return $findPeople->invoke(
            $job,
            $zohoDealInfo,
            null,
            auth()->user()->getCurrentCompany(),
            $this->receiver->app,
        );
    }
}

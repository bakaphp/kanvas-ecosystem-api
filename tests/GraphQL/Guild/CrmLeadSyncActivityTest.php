<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Activities\SyncCrmLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;

/**
 * The entry point is the generic `runWorkflowFromEntity` with this activity and no entity.
 *
 * Over HTTP the call carries the app key and no bearer token, so the acting user is the key's owner,
 * not the user the test is logged in as: both companies are configured, and the request names the
 * salesperson of whoever will act.
 *
 * Serial: it sets and restores a company setting, which is Redis-backed and shared by every parallel process.
 */
#[Group('serial')]
final class CrmLeadSyncActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'intelligence'];

    /** @var array<int, mixed> */
    private array $previousDealerNumbers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach ($this->companies() as $company) {
            $this->previousDealerNumbers[$company->getId()] = $company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
            $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, 'TESTDEALER001');
        }
    }

    protected function tearDown(): void
    {
        $key = ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value;

        foreach ($this->companies() as $company) {
            $previous = $this->previousDealerNumbers[$company->getId()] ?? null;
            $previous === null ? $company->del($key) : $company->set($key, $previous);
        }

        parent::tearDown();
    }

    public function testItCreatesAndAnswersWithTheKanvasIds(): void
    {
        $response = $this->callActivity($this->params())->assertSuccessful();
        $data = $response->json('data.runWorkflowFromEntity');

        $this->assertNotNull($data, $response->getContent());
        $this->assertSame('created_all', $data['outcome']);
        $this->assertTrue($data['people_created']);
        $this->assertTrue($data['lead_created']);
        $this->assertFalse($data['replayed']);
        $this->assertNotEmpty($data['people']['uuid']);
        $this->assertNotEmpty($data['lead']['uuid']);
        $this->assertSame('Ana Perez', Lead::getById($data['lead']['id'], app(Apps::class))->title);
    }

    public function testTheSameRequestIdIsReplayed(): void
    {
        $params = $this->params();

        $first = $this->callActivity($params)->json('data.runWorkflowFromEntity');
        $second = $this->callActivity($params)->json('data.runWorkflowFromEntity');

        $this->assertTrue($second['replayed']);
        $this->assertSame($first['lead']['id'], $second['lead']['id']);
    }

    public function testARefusalKeepsItsReasonAndField(): void
    {
        $params = $this->params();
        $params['lead']['source'] = ['id' => '1', 'name' => null];

        $response = $this->callActivity($params);

        $this->assertSame('validation_failed', $response->json('errors.0.extensions.reason'));
        $this->assertSame('lead.source.name', $response->json('errors.0.extensions.field'));
    }

    public function testARequestWithoutAClientIdIsRefused(): void
    {
        $params = $this->params();
        unset($params['identity']['client_id']);

        $response = $this->callActivity($params);

        $this->assertSame('validation_failed', $response->json('errors.0.extensions.reason'));
        $this->assertSame('identity.client_id', $response->json('errors.0.extensions.field'));
    }

    public function testWithoutALeadIdTheAnswerIsThePeopleAndNoLead(): void
    {
        $params = $this->params();
        $params['identity']['lead_id'] = null;

        $data = $this->callActivity($params)->json('data.runWorkflowFromEntity');

        $this->assertSame('people_only', $data['outcome']);
        $this->assertNull($data['lead']);
        $this->assertTrue($data['people_created']);
        $this->assertFalse($data['lead_created']);
        $this->assertNotEmpty($data['people']['uuid']);
    }

    public function testAnUnknownCrmTypeIsRefused(): void
    {
        $params = $this->params();
        $params['identity']['type'] = 'not_a_crm';

        $this->assertSame('identity.type', $this->callActivity($params)->json('errors.0.extensions.field'));
    }

    public function testAMalformedSectionIsARefusalNotACrash(): void
    {
        $params = $this->params();
        $params['participants'] = ['add' => [['lastname' => 'Perez']]];

        $response = $this->callActivity($params);

        $this->assertSame('validation_failed', $response->json('errors.0.extensions.reason'));
        $this->assertSame('request', $response->json('errors.0.extensions.field'));
    }

    public function testRequestIdIsRequired(): void
    {
        $params = $this->params();
        unset($params['request_id']);

        $this->assertSame('request_id', $this->callActivity($params)->json('errors.0.extensions.field'));
    }

    /**
     * runWorkflowFromEntity loads whatever entity id it is given, so the company must not come from it.
     */
    public function testTheCompanyIsTheCallersWhateverEntityTheCallNamesIs(): void
    {
        $user = auth()->user();
        $foreign = new Lead();
        $foreign->fill(['id' => 0, 'apps_id' => app(Apps::class)->getId(), 'companies_id' => 999999999]);

        $activity = new SyncCrmLeadActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );
        $data = $activity->execute($foreign, app(Apps::class), $this->params($user) + ['user' => $user]);

        $lead = Lead::getById($data['lead']['id'], app(Apps::class));
        $this->assertSame((int) $user->getCurrentCompany()->getId(), (int) $lead->companies_id);
    }

    public function testItNeedsAnAuthenticatedUser(): void
    {
        $activity = new SyncCrmLeadActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $this->expectExceptionMessage('authenticated user');

        $activity->execute(new Lead(), app(Apps::class), $this->params(auth()->user()));
    }

    private function callActivity(array $params)
    {
        return $this->graphQL('
            mutation($input: runWorkflowEntityInput!) {
                runWorkflowFromEntity(input: $input)
            }
        ', [
            'input' => [
                'entity_namespace' => Lead::class,
                'entity_id' => '0',
                'action' => 'pull',
                'activity_class' => SyncCrmLeadActivity::class,
                'params' => $params,
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => app(Apps::class)->keys()->first()->client_secret_id,
        ]);
    }

    private function params(?Users $actor = null): array
    {
        $user = $actor ?? $this->keyOwner();

        return [
            'request_id' => 'req-' . uniqid(),
            'identity' => [
                'type' => 'reynolds',
                'client_id' => (string) random_int(1000000, 9999999),
                'lead_id' => (string) random_int(1000000, 9999999),
            ],
            'customer' => [
                'firstname' => 'Ana',
                'lastname' => 'Perez',
                'emails' => ['crm.' . uniqid() . '@example.com'],
                'phones' => ['cell' => '7875550101'],
                'address' => ['address' => '123 Calle Sol', 'city' => 'San Juan', 'state' => 'PR', 'zip' => '00901'],
            ],
            'lead' => [
                'type' => ['id' => 6, 'name' => 'Walk-In'],
                'source' => ['id' => 102623, 'name' => '*Drove By'],
                'status' => 'Open',
                'salesperson' => ['id' => 469364, 'name' => $user->lastname . ', ' . $user->firstname],
            ],
        ];
    }

    private function keyOwner(): Users
    {
        return app(Apps::class)->keys()->first()->user;
    }

    /**
     * @return list<Companies>
     */
    private function companies(): array
    {
        $companies = [];

        foreach ([auth()->user(), $this->keyOwner()] as $user) {
            $company = Companies::getById($user->getCurrentCompany()->getId());
            $companies[$company->getId()] = $company;
        }

        return array_values($companies);
    }
}

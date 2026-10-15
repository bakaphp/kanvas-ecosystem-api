<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Tests\TestCase;

final class CrmLeadSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    private mixed $previousDealerNumber = null;

    protected function setUp(): void
    {
        parent::setUp();

        $company = $this->company();
        $this->previousDealerNumber = $company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, 'TESTDEALER001');
    }

    protected function tearDown(): void
    {
        $key = ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value;
        $this->previousDealerNumber === null
            ? $this->company()->del($key)
            : $this->company()->set($key, $this->previousDealerNumber);

        parent::tearDown();
    }

    public function testTheMutationCreatesAndReturnsKanvasObjects(): void
    {
        $input = $this->input();

        $response = $this->graphQL($this->mutation(), ['input' => $input])->assertSuccessful();
        $data = $response->json('data.syncCrmLead');

        $this->assertNotNull($data, $response->getContent());
        $this->assertSame('created_all', $data['outcome']);
        $this->assertTrue($data['people_created']);
        $this->assertTrue($data['lead_created']);
        $this->assertFalse($data['replayed']);
        $this->assertSame('Ana', $data['people']['firstname']);
        $this->assertSame('Ana Perez', $data['lead']['title']);
        $this->assertNotEmpty($data['lead']['uuid']);
        $this->assertSame([], $data['warnings']);
    }

    public function testTheSameRequestIsReplayed(): void
    {
        $input = $this->input();

        $first = $this->graphQL($this->mutation(), ['input' => $input])->json('data.syncCrmLead');
        $second = $this->graphQL($this->mutation(), ['input' => $input])->json('data.syncCrmLead');

        $this->assertTrue($second['replayed']);
        $this->assertSame($first['lead']['id'], $second['lead']['id']);
    }

    public function testAnErrorCarriesItsCodeAndField(): void
    {
        $input = $this->input();
        $input['lead']['source'] = ['id' => '1', 'name' => null];

        $response = $this->graphQL($this->mutation(), ['input' => $input]);

        $this->assertSame('validation_failed', $response->json('errors.0.extensions.reason'));
        $this->assertSame('lead.source.name', $response->json('errors.0.extensions.field'));
    }

    public function testAnUnknownCrmTypeIsRejectedBySchema(): void
    {
        $input = $this->input();
        $input['identity']['type'] = 'not_a_crm';

        $response = $this->graphQL($this->mutation(), ['input' => $input]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($response->json('data'));
    }

    private function mutation(): string
    {
        return '
            mutation($input: CrmLeadSyncInput!) {
                syncCrmLead(input: $input) {
                    outcome
                    people_created
                    lead_created
                    replayed
                    people { id firstname lastname }
                    lead { id uuid title }
                    warnings { code field }
                }
            }
        ';
    }

    private function input(): array
    {
        $user = auth()->user();

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

    private function company(): Companies
    {
        return Companies::getById(auth()->user()->getCurrentCompany()->getId());
    }
}

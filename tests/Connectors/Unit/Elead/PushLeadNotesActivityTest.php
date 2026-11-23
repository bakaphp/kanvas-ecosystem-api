<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Workflow\PushLeadNotesActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PushLeadNotesActivityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[DataProvider('submittedActions')]
    public function testSubmittedActionIncludesTheCustomerNameInTheEleadComment(
        string $verb,
        array $personAttributes,
        string $expectedName,
        ?string $opportunityId
    ): void {
        $lead = Mockery::mock(Lead::class)->makePartial();
        $people = Mockery::mock(People::class)->makePartial();
        $people->setRawAttributes($personAttributes);
        $lead->setRelation('people', $people);
        $lead->uuid = 'test-lead-uuid';
        $lead->shouldReceive('get')->with(CustomFieldEnum::LEAD_ID->value)->andReturn($opportunityId);

        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getMessage')->once()->andReturn([
            'verb' => $verb,
            'status' => ActionStatusEnum::SUBMITTED->value,
            'text' => 'Application',
            'data' => [3 => ['document' => 'drivers-license']],
        ]);

        $suffix = $opportunityId !== null
            ? 'Please first open SalesAssist Extension and then click on the banner.'
            : 'Please open the Chrome Extension to link and sync the opportunity first to view the banner.';
        $opportunity = Mockery::mock();
        $opportunity->shouldReceive('addComment')->once()->with(
            $expectedName . ' Application is ready to be imported into eLead. ' . $suffix
        );

        $reflection = new ReflectionClass(PushLeadNotesActivity::class);
        $reflection->getMethod('handleSpecialCases')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $message,
            $lead,
            $opportunity
        );
    }

    #[DataProvider('importedApplications')]
    public function testImportedApplicationStoresTheDataKeyTheExtensionPopupReads(
        CustomFieldEnum $flag,
        CustomFieldEnum $dataKey,
        string $expectedFlag,
        string $expectedDataKey
    ): void {
        $data = ['firstname' => 'Jane', 'lastname' => 'Doe'];
        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->shouldReceive('set')->once()->with($expectedDataKey, $data);
        $lead->shouldReceive('set')->once()->with(
            $expectedFlag,
            Mockery::on(fn (array $value) => $value['active'] === 1
                && $value['message'] === 'Ready'
                && isset($value['date']))
        );

        $reflection = new ReflectionClass(PushLeadNotesActivity::class);
        $reflection->getMethod('flagReadyForImport')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $lead,
            $flag,
            $dataKey,
            $data,
            'Ready'
        );
    }

    public function testSubmittedCoSignerFlagsTheCoBuyerApplicationForImport(): void
    {
        $people = Mockery::mock(People::class)->makePartial();
        $people->shouldReceive('getCellPhones')->andReturn(new Collection());
        $people->shouldReceive('get')->andReturn('elead-person');

        $company = Mockery::mock(Companies::class)->makePartial();
        $company->shouldReceive('get')->andReturn(null);

        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->setRelation('people', $people);
        $lead->setRelation('company', $company);
        $lead->shouldReceive('get')->andReturn('elead-opportunity');
        $lead->shouldReceive('set')->once()->with('cobuyer_imported', 1);
        $lead->shouldReceive('set')->once()->with(
            CustomFieldEnum::CO_BUYER_APP_DATA->value,
            Mockery::on(fn (array $data) => $data['firstname'] === 'John' && $data['lastname'] === 'Doe')
        );
        $lead->shouldReceive('set')->once()->with(
            CustomFieldEnum::CO_BUYER_APP_IMPORTER->value,
            Mockery::on(fn (array $value) => $value['active'] === 1)
        );

        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getMessage')->once()->andReturn([
            'verb' => ActionEnum::CO_SIGNER->value,
            'status' => ActionStatusEnum::SUBMITTED->value,
            'data' => [
                'form' => [
                    'personal' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                    ],
                ],
            ],
        ]);

        $reflection = new ReflectionClass(PushLeadNotesActivity::class);
        $result = $reflection->getMethod('handleActionByVerb')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $message,
            $lead,
            Mockery::mock()
        );

        $this->assertStringContainsString('co_buyer=true', $result['url']);
    }

    public static function importedApplications(): array
    {
        return [
            'credit application' => [
                CustomFieldEnum::CREDIT_APP_IMPORTER,
                CustomFieldEnum::CREDIT_APP_DATA,
                'credit_app_imported',
                'creditapp_data',
            ],
            'co-signer application' => [
                CustomFieldEnum::CO_BUYER_APP_IMPORTER,
                CustomFieldEnum::CO_BUYER_APP_DATA,
                'co_buyer_imported',
                'cobuyer_data',
            ],
        ];
    }

    public static function submittedActions(): array
    {
        return [
            'credit application with full name' => [
                ActionEnum::CREDIT_APP->value,
                ['firstname' => 'Jane', 'middlename' => 'Marie', 'lastname' => 'Doe'],
                'Jane Marie Doe',
                'elead-opportunity',
            ],
            'co-signer with imported name' => [
                ActionEnum::CO_SIGNER->value,
                ['name' => 'John Doe'],
                'John Doe',
                null,
            ],
            'drivers license with full name' => [
                ActionEnum::GET_DOCS->value,
                ['firstname' => 'Jane', 'lastname' => 'Doe'],
                'Jane Doe',
                'elead-opportunity',
            ],
        ];
    }
}

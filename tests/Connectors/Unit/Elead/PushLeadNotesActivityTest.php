<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
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
            ? 'Please first open SalesAssist Extension and then click on the banner or this url https://salink.app/?openInSa=true&uuid=test-lead-uuid&action=' . $verb . '&lDID=' . $opportunityId
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

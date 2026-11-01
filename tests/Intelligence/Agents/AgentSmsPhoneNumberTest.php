<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentSmsPhoneNumberTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    private const string CALLER_FROM = '+15550001111';

    private Apps $testApp;
    private Companies $company;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
        $this->number = '+1555' . fake()->unique()->numerify('#######');
    }

    public function testTheAgentNumberWinsOverTheCallersFrom(): void
    {
        $this->assertSame($this->number, $this->sentFrom($this->agent(sms: $this->number)));
    }

    public function testAnAgentWithoutANumberKeepsTheCallersFrom(): void
    {
        $this->assertSame(self::CALLER_FROM, $this->sentFrom($this->agent()));
    }

    public function testNoAgentKeepsTheCallersFrom(): void
    {
        $this->assertSame(self::CALLER_FROM, $this->sentFrom(null));
    }

    public function testAVoiceNumberIsNotUsedForTexts(): void
    {
        $this->assertSame(self::CALLER_FROM, $this->sentFrom($this->agent(voice: $this->number)));
    }

    public function testFindsTheAgentHoldingANumberInAnyFormat(): void
    {
        $agent = $this->agent(sms: $this->number);
        $formatted = '+1 (555) ' . substr($this->number, 5, 3) . '-' . substr($this->number, 8);

        $this->assertSame($agent->getId(), AgentsRepository::getAgentHoldingPhoneNumber($formatted)?->getId());
        $this->assertNull(AgentsRepository::getAgentHoldingPhoneNumber($this->number, AgentPhoneChannelEnum::VOICE));
        $this->assertNull(AgentsRepository::getAgentHoldingPhoneNumber(number: $this->number, exceptAgentId: $agent->getId()));
    }

    public function testCreatingAnAgentWithATakenNumberIsRejected(): void
    {
        $this->agent(voice: $this->number);

        $response = $this->saveAgent('createAiAgent(input: $input)', ['sms_config' => ['phone_number' => $this->number]]);

        $this->assertStringContainsString('already assigned', (string) $response->json('errors.0.message'));
    }

    public static function phoneConfigProvider(): array
    {
        return [
            'voice' => ['voice_config'],
            'SMS' => ['sms_config'],
        ];
    }

    #[DataProvider('phoneConfigProvider')]
    public function testJsonPhoneConfigCannotClaimATakenNumberOnCreate(string $column): void
    {
        $this->agent(voice: $this->number);
        $count = Agent::query()->fromApp($this->testApp)->fromCompany($this->company)->count();

        $response = $this->saveAgent('createAiAgent(input: $input)', [
            $column => json_encode(['phone_number' => $this->number]),
        ]);

        $this->assertStringContainsString('already assigned', (string) $response->json('errors.0.message'));
        $this->assertSame($count, Agent::query()->fromApp($this->testApp)->fromCompany($this->company)->count());
    }

    #[DataProvider('phoneConfigProvider')]
    public function testJsonPhoneConfigCannotClaimATakenNumberOnUpdate(string $column): void
    {
        $this->agent(sms: $this->number);
        $agent = $this->agent();

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', [
            $column => json_encode(['phone_number' => $this->number]),
        ]);

        $this->assertStringContainsString('already assigned', (string) $response->json('errors.0.message'));
        $this->assertNull($agent->fresh()->{$column});
    }

    #[DataProvider('phoneConfigProvider')]
    public function testJsonPhoneConfigNormalizesTheNumberAndPreservesOtherSettings(string $column): void
    {
        $formatted = '+1 (555) ' . substr($this->number, 5, 3) . '-' . substr($this->number, 8);

        $response = $this->saveAgent('createAiAgent(input: $input)', [
            $column => json_encode(['phone_number' => $formatted, 'enabled' => true]),
        ]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $agent = Agent::getByIdFromCompanyApp($response->json('data.agent.id'), $this->company, $this->testApp);
        $this->assertSame($this->number, $agent->{$column}['phone_number']);
        $this->assertTrue($agent->{$column}['enabled']);
    }

    public function testOneAgentMayUseTheSameNumberForVoiceAndSms(): void
    {
        $response = $this->saveAgent('createAiAgent(input: $input)', [
            'voice_config' => ['phone_number' => $this->number],
            'sms_config' => ['phone_number' => $this->number],
        ]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame($this->number, $response->json('data.agent.sms_config.phone_number'));
    }

    public function testAnUpdateThatOmitsSmsConfigKeepsIt(): void
    {
        $agent = $this->agent(sms: $this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', []);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame($this->number, $agent->fresh()->smsPhoneNumber());
    }

    public function testAnUpdateWithANullSmsConfigClearsIt(): void
    {
        $agent = $this->agent(sms: $this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', ['sms_config' => null]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertNull($agent->fresh()->smsPhoneNumber());
    }

    public function testASavedDuplicateDoesNotBlockAnUnrelatedUpdate(): void
    {
        $this->agent(sms: $this->number);
        $duplicate = $this->agent(sms: $this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $duplicate->getId() . ', input: $input)', [
            'sms_config' => ['phone_number' => $this->number],
        ]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
    }

    public function testASmsConfigThatIsNotAnObjectIsRejectedAndKeepsTheNumber(): void
    {
        $agent = $this->agent(sms: $this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', ['sms_config' => 'not-json']);

        $this->assertStringContainsString('must be objects', (string) $response->json('errors.0.message'));
        $this->assertSame($this->number, $agent->fresh()->smsPhoneNumber());
    }

    public function testANumericPhoneNumberIsSavedLikeTheSameDigitsAsAString(): void
    {
        $digits = (int) substr($this->number, 1);

        $response = $this->saveAgent('createAiAgent(input: $input)', ['sms_config' => ['phone_number' => $digits]]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame((string) $digits, $response->json('data.agent.sms_config.phone_number'));
    }

    private function saveAgent(string $field, array $input)
    {
        return $this->graphQL('
            mutation($input: AgentAiInput!) {
                agent: ' . $field . ' {
                    id
                    sms_config
                }
            }
        ', [
            'input' => [
                'agent_type_id' => AgentType::factory()->withAppId($this->testApp->getId())->create()->getId(),
                'name' => 'Agent ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                ...$input,
            ],
        ]);
    }

    private function sentFrom(?Agent $agent): string
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create();

        $action = new class ($lead) extends SendMessageToLeadAction {
            public ?string $capturedFrom = null;

            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                $this->capturedFrom = $from;

                return ['success' => true];
            }
        };

        $action->execute(
            channel: LeadCommunicationChannelEnum::SMS->value,
            message: 'hello',
            from: self::CALLER_FROM,
            fromAgent: $agent,
        );

        return (string) $action->capturedFrom;
    }

    private function agent(?string $voice = null, ?string $sms = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'voice_config' => $voice !== null ? ['phone_number' => $voice] : null,
                'sms_config' => $sms !== null ? ['phone_number' => $sms] : null,
            ]);
    }
}

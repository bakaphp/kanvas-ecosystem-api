<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use App\Console\Commands\Intelligence\Agents\SetAgentPhoneNumberCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Stubs\Intelligence\FakeTwilioNumbersClient;
use Tests\TestCase;
use Twilio\Rest\Client;

class SetAgentPhoneNumberCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'workflow'];

    private const string COMMAND = 'kanvas:agent-set-phone-number';

    private Apps $testApp;
    private Companies $company;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::factory()->create();
        $this->number = '+1555' . fake()->unique()->numerify('#######');
    }

    public function testDryRunWritesNothingAndPredictsAConflict(): void
    {
        $this->fakeTwilio(new FakeTwilioNumbersClient(smsUrl: 'https://other-system.test/hook'));
        $agent = $this->agent();

        $this->artisan(self::COMMAND, $this->commandOptions($agent) + ['--dry-run' => true])
            ->expectsOutputToContain('conflict, webhook left in place')
            ->assertExitCode(0);

        $this->assertNull($agent->fresh()->smsPhoneNumber());
        $this->assertSame(0, $this->receivers()->count());
    }

    public function testDryRunRecognisesTheCompanysExistingReceiver(): void
    {
        $receiver = ReceiverWebhook::factory()->create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'action_id' => WorkflowAction::factory()->create(['model_name' => ProcessTwilioWebhookJob::class])->getId(),
            'is_active' => 1,
        ]);
        $this->fakeTwilio(new FakeTwilioNumbersClient(smsUrl: $receiver->getUrl()));

        $this->artisan(self::COMMAND, $this->commandOptions($this->agent()) + ['--dry-run' => true])
            ->expectsOutputToContain('wired, Twilio unchanged')
            ->assertExitCode(0);
    }

    public function testDryRunFailsWithoutCompanyCredentials(): void
    {
        $this->artisan(self::COMMAND, $this->commandOptions($this->agent()) + ['--dry-run' => true])
            ->expectsOutputToContain('Twilio credentials are not set')
            ->assertExitCode(1);
    }

    public function testDryRunRejectsANumberThatCannotReceiveSms(): void
    {
        $twilio = new FakeTwilioNumbersClient(sms: false);
        $this->fakeTwilio($twilio);
        $agent = $this->agent();

        $this->artisan(self::COMMAND, $this->commandOptions($agent) + ['--dry-run' => true])
            ->expectsOutputToContain('cannot send or receive SMS')
            ->assertExitCode(1);

        $this->assertNull($agent->fresh()->smsPhoneNumber());
        $this->assertSame(0, $this->receivers()->count());
        $this->assertSame([], $twilio->updates);
    }

    public function testRunSavesTheNumberAndReportsTheStatus(): void
    {
        $this->company->del(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $agent = $this->agent();

        $this->artisan(self::COMMAND, $this->commandOptions($agent))
            ->expectsOutputToContain('Twilio credentials are not set')
            ->assertExitCode(1);

        $this->assertSame($this->number, $agent->fresh()->smsPhoneNumber());
    }

    public function testATakenNumberIsRefused(): void
    {
        $this->agent(['sms_config' => ['phone_number' => $this->number]]);
        $agent = $this->agent();

        $this->artisan(self::COMMAND, $this->commandOptions($agent))
            ->expectsOutputToContain('already assigned')
            ->assertExitCode(1);

        $this->assertNull($agent->fresh()->smsPhoneNumber());
    }

    public function testAnInvalidNumberOrChannelIsRefused(): void
    {
        $agent = $this->agent();

        $this->artisan(self::COMMAND, ['--number' => 'abc'] + $this->commandOptions($agent))->assertExitCode(1);
        $this->artisan(self::COMMAND, ['--channel' => 'fax'] + $this->commandOptions($agent))->assertExitCode(1);
    }

    public function testAnUnknownAgentIsRefused(): void
    {
        $this->artisan(self::COMMAND, ['--agent' => 0, '--number' => $this->number])->assertExitCode(1);
    }

    private function commandOptions(Agent $agent): array
    {
        return [
            '--agent' => $agent->getId(),
            '--number' => $this->number,
            '--channel' => 'SMS',
        ];
    }

    private function receivers(): Builder
    {
        return ReceiverWebhook::query()->where('companies_id', $this->company->getId())->notDeleted();
    }

    private function agent(array $attributes = []): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create($attributes);
    }

    private function fakeTwilio(FakeTwilioNumbersClient $fake): void
    {
        $command = new class ($fake) extends SetAgentPhoneNumberCommand {
            public function __construct(private FakeTwilioNumbersClient $fake)
            {
                parent::__construct();
            }

            protected function twilio(Companies $company): Client
            {
                return $this->fake;
            }
        };

        $this->app->make(Kernel::class)->registerCommand($command);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Support\ProviderRollout;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Phone\SetAgentPhoneNumberAction;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Throwable;
use Twilio\Rest\Client as TwilioRestClient;

/**
 * Gives an agent that already uses a number its phone config, for agents set up before numbers were
 * saved on the agent. A voice webhook another system set is never replaced. A text number becomes
 * a Communication connection the agent owns, and that connection's setup points the number at the
 * company's receiver.
 */
class SetAgentPhoneNumberCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent-set-phone-number
        {--agent= : Agent id}
        {--number= : Phone number}
        {--channel=sms : sms or voice}
        {--dry-run : Report what would happen, writing nothing to Kanvas or Twilio}';

    protected $description = 'Save an existing phone number on an agent and set up what answers it';

    public function handle(): int
    {
        $channel = AgentPhoneChannelEnum::tryFrom(strtolower((string) $this->option('channel')));
        if ($channel === null) {
            $this->error('--channel must be sms or voice.');

            return self::FAILURE;
        }

        $number = AgentsRepository::cleanPhoneNumber((string) $this->option('number'));
        if ($number === '') {
            $this->error('--number is not a valid phone number.');

            return self::FAILURE;
        }

        try {
            $agent = Agent::getById((int) $this->option('agent'));
        } catch (ModelNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->overwriteAppService($agent->app);
        $this->line("Agent {$agent->getId()} ({$agent->name}), app {$agent->apps_id}, company {$agent->companies_id}");

        try {
            SetAgentPhoneNumberAction::assertNumberAvailable($number, $agent);
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->option('dry-run')
            ? $this->dryRun($agent, $channel, $number)
            : $this->apply($agent, $channel, $number);
    }

    protected function twilio(Companies $company): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($company);
    }

    private function apply(Agent $agent, AgentPhoneChannelEnum $channel, string $number): int
    {
        $result = new SetAgentPhoneNumberAction($agent, $channel, $number)->execute();

        $this->line("{$channel->value} number saved: {$number}");
        $this->line("Status: {$result['status']} ({$result['message']})");
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function dryRun(Agent $agent, AgentPhoneChannelEnum $channel, string $number): int
    {
        $this->line('DRY RUN, nothing written.');
        $this->line('Current number: ' . ($agent->phoneNumber($channel) ?? 'none'));

        try {
            $numbers = $this->twilio(Companies::getById($agent->companies_id))->incomingPhoneNumbers->read(['phoneNumber' => $number], 1);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($numbers === []) {
            $this->error("{$number} is not a number in the company's Twilio account.");

            return self::FAILURE;
        }

        if ($channel === AgentPhoneChannelEnum::SMS && ! $numbers[0]->capabilities?->getSms()) {
            $this->error("{$number} cannot send or receive SMS.");

            return self::FAILURE;
        }

        $current = trim((string) $numbers[0]->{$channel->twilioUrlField()});
        $this->line('Webhook on Twilio: ' . ($current ?: 'none'));
        $this->line('Would result in: ' . (
            $channel === AgentPhoneChannelEnum::SMS
                ? $this->predictText($agent, $number)
                : $this->predictVoice($current)
        ));

        return self::SUCCESS;
    }

    protected function communicationManagesTwilio(Agent $agent): bool
    {
        return new ProviderRollout()->manages(IntegrationsEnum::TWILIO, $agent->app, $agent->company);
    }

    /**
     * A text number has no webhook of its own to keep or replace: it becomes a connection of the
     * company, and that connection's setup is what points the number at the company's receiver.
     */
    private function predictText(Agent $agent, string $number): string
    {
        if (! $this->communicationManagesTwilio($agent)) {
            return 'error, connect the Twilio integration for this company first';
        }

        $connection = Communication::for($agent->app, $agent->company)
            ->connectionFor(ChannelTypeEnum::SMS, fromAccount: $number);
        $answeredByAgent = $connection?->agent()?->getId() === $agent->getId();

        return $answeredByAgent && $connection->unavailableReason() === null
            ? 'wired, this agent already answers the number'
            : 'pending, the number becomes a connection this agent answers once its setup has run';
    }

    private function predictVoice(string $current): string
    {
        $target = VoiceRuntimeConfig::inboundWebhookUrl();

        return match (true) {
            $target === '' => 'error, the voice runtime URL is not configured',
            $current === $target => 'wired, Twilio unchanged',
            $current === '' => 'wired, the voice runtime webhook is set on Twilio',
            VoiceRuntimeConfig::isRuntimeInboundUrl($current) => 'wired, moved from another voice runtime',
            default => 'conflict, webhook left in place',
        };
    }
}

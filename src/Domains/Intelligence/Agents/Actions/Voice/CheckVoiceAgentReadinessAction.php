<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Support\Str;
use Kanvas\Connectors\Twilio\Actions\DescribeCompanyTwilioAction;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Enums\ReadinessStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Throwable;

/**
 * Everything that has to be true for a voice agent to take and place calls, checked live, so the
 * admin sees what is missing on the agent page instead of finding out from a failed call.
 *
 * Each check carries an `action` the admin UI turns into a fix-it button. The agent is ready when
 * no check is an error; warnings (trial account, default voice, no test call yet) don't block it.
 */
class CheckVoiceAgentReadinessAction
{
    private const array LABELS = [
        'runtime' => 'Voice runtime',
        'twilio' => 'Twilio account',
        'balance' => 'Twilio balance',
        'phone_number' => 'Phone number',
        'inbound' => 'Incoming calls',
        'prompt' => 'Instructions',
        'call_model' => 'Call model',
        'voice' => 'Voice',
        'greeting' => 'Greeting',
        'test_call' => 'Test call',
    ];

    public function __construct(private readonly Agent $agent)
    {
    }

    /**
     * @return array{ready: bool, checks: list<array{key: string, label: string, status: string, message: string, action: string|null}>}
     */
    public function execute(): array
    {
        $voice = $this->agent->voice_config ?? [];
        $checks = [
            $this->runtime(),
            ...$this->twilioChecks(),
            ...$this->numberChecks(),
            $this->prompt(),
            $this->callModel(),
            $this->voice($voice),
            $this->greeting($voice),
            $this->testCall($voice),
        ];

        return [
            'ready' => array_all($checks, static fn (array $check): bool => $check['status'] !== ReadinessStatusEnum::ERROR->value),
            'checks' => $checks,
        ];
    }

    protected function twilio(): DescribeCompanyTwilioAction
    {
        return new DescribeCompanyTwilioAction($this->agent->company);
    }

    protected function phoneNumbers(): ListCompanyPhoneNumbersAction
    {
        return new ListCompanyPhoneNumbersAction($this->agent->company);
    }

    private function runtime(): array
    {
        if (VoiceRuntimeConfig::url($this->agent->app) === '' || VoiceRuntimeConfig::apiToken($this->agent->app) === '') {
            return $this->error('runtime', 'The voice runtime URL or token is not configured for this app.');
        }

        return $this->ok('runtime', 'Configured.');
    }

    /**
     * The connection itself, then its balance. A problem with the default From number is left out:
     * an agent with its own number never dials from it.
     *
     * @return list<array<string, string|null>>
     */
    private function twilioChecks(): array
    {
        $twilio = $this->twilio()->execute();
        if (! $twilio['connected']) {
            return [$this->error('twilio', (string) $twilio['error'], 'connect_twilio')];
        }

        $problems = array_column($twilio['problems'], 'message', 'key');
        $name = ($twilio['account_name'] ?? 'Twilio') . " (…{$twilio['account_sid_suffix']})";
        $account = match (true) {
            isset($problems['status']) => $this->error('twilio', $problems['status'], 'connect_twilio'),
            isset($problems['trial']) => $this->warning('twilio', "{$name}. {$problems['trial']}", 'connect_twilio'),
            default => $this->ok('twilio', "Connected to {$name}.", 'connect_twilio'),
        };

        $balance = match (true) {
            isset($problems['balance']) => $this->error('balance', $problems['balance']),
            $twilio['balance'] === null => $this->warning('balance', 'Twilio did not report the balance; check it in the Twilio console.'),
            default => $this->ok('balance', number_format($twilio['balance'], 2) . " {$twilio['currency']}."),
        };

        return [$account, $balance];
    }

    /**
     * The agent's number, and whether calls to it reach the voice runtime.
     *
     * @return list<array<string, string|null>>
     */
    private function numberChecks(): array
    {
        $number = $this->agent->phoneNumber(AgentPhoneChannelEnum::VOICE);
        if ($number === null) {
            return [$this->error('phone_number', 'Pick the number this agent answers and calls from.', 'pick_number')];
        }

        try {
            $twilioNumber = $this->phoneNumbers()->number($number);
        } catch (Throwable) {
            // The Twilio check already says why the account can't be read.
            return [$this->warning('phone_number', "{$number} could not be checked on Twilio.", 'pick_number')];
        }

        if ($twilioNumber === null) {
            return [$this->error('phone_number', "{$number} is not on the connected Twilio account.", 'pick_number')];
        }

        $voiceUrl = $twilioNumber['voice_url'];
        $inbound = match ($voiceUrl) {
            VoiceRuntimeConfig::inboundWebhookUrl($this->agent->app) => $this->ok('inbound', "Calls to {$number} reach this agent."),
            null => $this->error('inbound', "{$number} has no incoming-call webhook, so callers hear an error.", 'rewire_number'),
            default => $this->error('inbound', "Calls to {$number} go to {$voiceUrl}, not this agent.", 'rewire_number'),
        };

        return [$this->ok('phone_number', $number, 'pick_number'), $inbound];
    }

    private function prompt(): array
    {
        if (new VoiceAgentSpecService($this->agent, $this->agent->app)->systemInstruction() === '') {
            return $this->error('prompt', 'The agent has no instructions, so it doesn\'t know who it is on a call.', 'edit_prompt');
        }

        return $this->ok('prompt', 'Set.', 'edit_prompt');
    }

    private function callModel(): array
    {
        $config = AgentProviderService::voiceConfig($this->agent);
        if ($config !== null) {
            return $this->ok('call_model', "{$config->provider} · {$config->model}.", 'edit_voice');
        }

        if ((int) ($this->agent->voice_config['llm_config_id'] ?? 0) > 0) {
            return $this->warning('call_model', 'The selected call model was removed or turned off, so calls use the default Gemini model.', 'edit_voice');
        }

        return $this->ok('call_model', 'Default (Gemini).', 'edit_voice');
    }

    /**
     * @param array<string, mixed> $voice
     */
    private function voice(array $voice): array
    {
        if (Str::trimToNull((string) ($voice['tts']['voice_id'] ?? $voice['voice_id'] ?? '')) === null) {
            return $this->warning('voice', 'No voice picked, so calls use the runtime\'s default voice.', 'edit_voice');
        }

        return $this->ok('voice', 'Set.', 'edit_voice');
    }

    /**
     * @param array<string, mixed> $voice
     */
    private function greeting(array $voice): array
    {
        if (Str::trimToNull((string) ($voice['greeting'] ?? '')) === null) {
            return $this->warning('greeting', 'No greeting, so the agent opens with a generic line.', 'edit_voice');
        }

        return $this->ok('greeting', 'Set.', 'edit_voice');
    }

    /**
     * @param array<string, mixed> $voice
     */
    private function testCall(array $voice): array
    {
        $at = $voice['last_test_call']['at'] ?? null;
        if ($at === null) {
            return $this->warning('test_call', 'Place a test call to hear the agent before going live.', 'test_call');
        }

        return $this->ok('test_call', "Last test call placed {$at}.", 'test_call');
    }

    private function ok(string $key, string $message, ?string $action = null): array
    {
        return $this->check(
            ReadinessStatusEnum::OK,
            $key,
            $message,
            $action
        );
    }

    private function warning(string $key, string $message, ?string $action = null): array
    {
        return $this->check(
            ReadinessStatusEnum::WARNING,
            $key,
            $message,
            $action
        );
    }

    private function error(string $key, string $message, ?string $action = null): array
    {
        return $this->check(
            ReadinessStatusEnum::ERROR,
            $key,
            $message,
            $action
        );
    }

    /**
     * @return array{key: string, label: string, status: string, message: string, action: string|null}
     */
    private function check(
        ReadinessStatusEnum $status,
        string $key,
        string $message,
        ?string $action
    ): array {
        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'status' => $status->value,
            'message' => $message,
            'action' => $action,
        ];
    }
}

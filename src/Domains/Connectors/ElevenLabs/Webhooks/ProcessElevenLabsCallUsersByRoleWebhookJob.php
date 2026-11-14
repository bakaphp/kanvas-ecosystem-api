<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;
use Throwable;
use Twilio\TwiML\VoiceResponse;

#[WorkflowAction(
    name: 'ElevenLabs Call Staff By Role',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one PLACES OUTBOUND CALLS to the staff holding a named role — '
        . 'everyone in that role with a phone number on file. It rings real people, so keep the role '
        . 'narrow.',
)]
class ProcessElevenLabsCallUsersByRoleWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $role = isset($payload['role']) ? trim((string) $payload['role']) : '';

        if ($role === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Role is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;

        try {
            $users = UsersRepository::getCompanyAppUserByRole($company, $app, $role)
                ->groupBy('users.id')
                ->limit(50)
                ->get();
        } catch (ModelNotFoundException) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => "Role '{$role}' not found"];
        }

        $agents = $users->map(function (Users $user): array {
            $phone = trim((string) ($user->cell_phone_number ?? $user->phone_number ?? ''));

            return [
                'id' => (string) $user->uuid,
                'firstname' => (string) $user->firstname,
                'lastname' => (string) $user->lastname,
                'phone' => $phone,
            ];
        })->filter(fn (array $a): bool => $a['phone'] !== '')->values();

        if ($agents->isEmpty()) {
            return [
                'message' => 'No users with phone numbers found for role',
                'role' => $role,
                'matches' => [],
            ];
        }

        $leadId = isset($payload['lead_id']) ? (string) $payload['lead_id'] : '';
        $callId = Str::uuid()->toString();

        $twiml = $this->buildTwiml($agents->all(), $callId, $leadId, $payload);
        $twimlXml = (string) $twiml;

        $twilioCallSid = null;
        $callError = null;
        $to = isset($payload['to']) && (string) $payload['to'] !== '' ? (string) $payload['to'] : null;

        if ($to !== null) {
            try {
                $fromPhone = $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
                    ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value);

                if ($fromPhone === null || (string) $fromPhone === '') {
                    $callError = 'Twilio from phone number is not configured for company';
                } else {
                    $call = TwilioClient::getInstanceByCompany($company)->calls->create(
                        $to,
                        (string) $fromPhone,
                        ['twiml' => $twimlXml],
                    );
                    $twilioCallSid = $call->sid;
                }
            } catch (Throwable $e) {
                $callError = $e->getMessage();
            }
        }

        return [
            'message' => 'Call dispatched to users by role',
            'role' => $role,
            'call_id' => $callId,
            'lead_id' => $leadId !== '' ? $leadId : null,
            'twiml' => $twimlXml,
            'twilio_call_sid' => $twilioCallSid,
            'twilio_call_error' => $callError,
            'matches' => $agents->all(),
        ];
    }

    /**
     * @param array<int, array<string, string>> $agents
     */
    protected function buildTwiml(array $agents, string $callId, string $leadId, array $payload): VoiceResponse
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $actionUrl = $this->resolveCallbackUrl(
            $payload['action_url'] ?? null,
            "{$baseUrl}/webhooks/twilio/dial-complete",
            ['lead_id' => $leadId, 'call_id' => $callId],
        );
        $statusBaseUrl = $this->resolveCallbackUrl(
            $payload['status_callback_url'] ?? null,
            "{$baseUrl}/webhooks/twilio/agent-status",
            ['lead_id' => $leadId, 'call_id' => $callId],
        );

        $voiceResponse = new VoiceResponse();
        $dial = $voiceResponse->dial(
            null,
            [
                'action' => $actionUrl,
                'method' => 'POST',
            ],
        );

        foreach ($agents as $agent) {
            $dial->number(
                $agent['phone'],
                [
                    'statusCallback' => $this->appendQuery($statusBaseUrl, ['agent_id' => $agent['id']]),
                    'statusCallbackEvent' => 'initiated ringing answered completed',
                    'statusCallbackMethod' => 'POST',
                ],
            );
        }

        return $voiceResponse;
    }

    /**
     * @param array<string, string> $query
     */
    protected function resolveCallbackUrl(mixed $override, string $default, array $query): string
    {
        $url = is_string($override) && $override !== '' ? $override : $default;

        return $this->appendQuery($url, $query);
    }

    /**
     * @param array<string, string> $query
     */
    protected function appendQuery(string $url, array $query): string
    {
        $query = array_filter($query, fn (string $v): bool => $v !== '');

        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }
}

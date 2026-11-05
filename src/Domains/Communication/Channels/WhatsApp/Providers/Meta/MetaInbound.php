<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\BurstConfigEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Contracts\VerifiesEndpoint;
use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Meta's side of the communication webhook. One payload can carry messages and delivery statuses
 * for several numbers at once (`entry[].changes[].value`), each change naming the number it is for.
 */
class MetaInbound implements InboundSource, VerifiesEndpoint
{
    private const array MEDIA_TYPES = [
        'image',
        'video',
        'audio',
        'document',
        'sticker',
    ];

    /**
     * Not a turn for the agent: a reaction to one of our messages, or Meta telling us something
     * about the account.
     */
    private const array IGNORED_TYPES = [
        'reaction',
        'system',
        'unsupported',
        'ephemeral',
    ];

    /**
     * What Meta is told to echo back when it verifies a callback URL. Not a secret anyone hands us —
     * we pick it, so it is generated the first time a number is subscribed.
     */
    public static function verifyToken(AppInterface $app): string
    {
        $token = $app->get(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $app->setEncrypted(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value, $token);
        }

        return $token;
    }

    /**
     * `X-Hub-Signature-256` is an HMAC of the raw body with the Meta app's secret. Without a secret
     * configured nothing can be verified, so nothing is accepted.
     */
    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = $receiver->app->get(ConfigurationEnum::APP_SECRET->value);
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! is_string($secret) || $secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals(
            'sha256=' . hash_hmac('sha256', $request->getContent(), $secret),
            $signature
        );
    }

    /**
     * The one URL every app's WABAs post to: a WABA takes one callback override, and whose numbers
     * a payload carries is only known from the payload. Under /v1/receiver/ so it reads as a Kanvas
     * receiver.
     */
    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/v1/receiver/meta';
    }

    #[Override]
    public function challenge(Request $request, ReceiverWebhook $receiver): ?string
    {
        return $this->answerChallenge($request, $receiver->app);
    }

    public function answerChallenge(Request $request, AppInterface $app): ?string
    {
        $token = $app->get(ConfigurationEnum::WEBHOOK_VERIFY_TOKEN->value);

        if ($this->hubParameter($request, 'mode') !== 'subscribe' || ! is_string($token) || $token === '') {
            return null;
        }

        if (! hash_equals($token, $this->hubParameter($request, 'verify_token'))) {
            return null;
        }

        return $this->hubParameter($request, 'challenge');
    }

    /**
     * PHP turns the dot in `hub.mode` into an underscore when it parses a query string; a request
     * built another way may keep it.
     */
    private function hubParameter(Request $request, string $name): string
    {
        return (string) ($request->query('hub_' . $name) ?? $request->query('hub.' . $name) ?? '');
    }

    /**
     * The claim on the number the payload is for. A payload comes from one WABA, so its numbers
     * belong to one company: the first claimed one names it.
     */
    public function claimFor(array $payload): ?InboundClaim
    {
        foreach ($this->changes($payload) as $value) {
            $number = (string) ($value['metadata']['display_phone_number'] ?? '');
            $claim = $number === ''
                ? null
                : InboundClaim::forIdentity(MetaWhatsAppHandler::slug(), ChannelTypeEnum::WHATSAPP, $number);

            if ($claim !== null) {
                return $claim;
            }
        }

        return null;
    }

    #[Override]
    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        $messages = [];

        foreach ($this->changes($payload) as $value) {
            $ourNumber = (string) ($value['metadata']['display_phone_number'] ?? '');

            foreach ($value['messages'] ?? [] as $message) {
                if (in_array($message['type'] ?? null, self::IGNORED_TYPES, true) || empty($message['from'])) {
                    continue;
                }

                $messages[] = $this->parse(
                    $message,
                    $value['metadata'] ?? [],
                    $ourNumber
                );
            }
        }

        return $messages;
    }

    /**
     * Routed by the message id Meta gave us when we sent it. A status for a message that never went
     * through this domain is counted and left alone.
     */
    #[Override]
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        $statuses = [];

        foreach ($this->changes($payload) as $value) {
            array_push($statuses, ...($value['statuses'] ?? []));
        }

        if ($statuses === []) {
            return null;
        }

        $summary = ['recorded' => 0, 'duplicates' => 0, 'unmatched' => 0];

        foreach ($statuses as $status) {
            $deliveryStatus = StatusMap::toDeliveryStatus((string) ($status['status'] ?? ''));
            $delivery = $deliveryStatus !== null && ! empty($status['id'])
                ? Delivery::query()
                    ->fromApp($receiver->app)
                    ->where('provider', MetaWhatsAppHandler::slug())
                    ->where('external_id', (string) $status['id'])
                    ->first()
                : null;

            if ($delivery === null) {
                $summary['unmatched']++;

                continue;
            }

            $error = $status['errors'][0] ?? null;

            $recorded = new RecordDeliveryEventAction(
                delivery: $delivery,
                status: $deliveryStatus,
                providerStatus: strtolower((string) $status['status']),
                payload: $status,
                occurredAt: isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : null,
                errorCode: isset($error['code']) ? (string) $error['code'] : null,
                error: $error['error_data']['details'] ?? $error['title'] ?? null
            )->execute();

            $summary[$recorded ? 'recorded' : 'duplicates']++;
        }

        return $summary;
    }

    /**
     * Meta hands out a media id; the file is behind two authenticated calls.
     */
    #[Override]
    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        $client = Client::forCompany($message->app, $message->company);
        $media = $client->media($attachment->url);

        return new StoreInboundFileAction(
            $message,
            $client->downloadMedia($media['url']),
            $media['mime_type'] ?? $attachment->contentType,
            'whatsapp'
        )->execute();
    }

    #[Override]
    public function burstPolicy(InboundMessage $inbound, Companies $company): BurstPolicy
    {
        $idle = BurstConfigEnum::BURST_IDLE_SECONDS->getInt($company);

        return new BurstPolicy(
            correlationKeys: ['whatsapp:' . Str::digitsOnly($inbound->from)],
            chainIdleSeconds: $idle,
            closeIdleSeconds: $idle,
            maxSeconds: BurstConfigEnum::BURST_MAX_SECONDS->getInt($company),
            jitterSeconds: BurstConfigEnum::BURST_JITTER_SECONDS->getInt($company),
        );
    }

    #[Override]
    public function burstHandler(): string
    {
        return FlushInboundBurstAction::class;
    }

    /**
     * Unlike a carrier, Meta does not answer HELP for us.
     */
    #[Override]
    public function answersHelpItself(): bool
    {
        return false;
    }

    /**
     * @return list<array<string, mixed>> each change's `value` that carries messages or statuses
     */
    private function changes(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return [];
        }

        $values = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'messages' && is_array($change['value'] ?? null)) {
                    $values[] = $change['value'];
                }
            }
        }

        return $values;
    }

    private function parse(array $message, array $metadata, string $ourNumber): InboundMessage
    {
        $type = (string) ($message['type'] ?? 'text');
        $from = Str::toE164((string) $message['from']);
        $attachments = [];

        if (in_array($type, self::MEDIA_TYPES, true) && isset($message[$type]['id'])) {
            $attachments[] = new InboundAttachment((string) $message[$type]['id'], (string) ($message[$type]['mime_type'] ?? ''));
        }

        return new InboundMessage(
            provider: MetaWhatsAppHandler::slug(),
            channel: ChannelTypeEnum::WHATSAPP,
            inboundIdentity: $ourNumber,
            from: $from,
            providerMessageId: (string) $message['id'],
            conversationSlug: WhatsAppTransport::channelSlug($from),
            messageVerb: WhatsAppTransport::MESSAGE_VERB,
            messageSlug: 'meta-' . Str::slug((string) $message['id']),
            text: $this->text($type, $message),
            // The number it reached, kept on the message so a reply can find its way back.
            raw: $message + ['metadata' => $metadata],
            attachments: $attachments,
            personTags: ['whatsapp', 'meta'],
        );
    }

    private function text(string $type, array $message): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'location' => isset($message['location']['latitude'], $message['location']['longitude'])
                ? trim(($message['location']['name'] ?? '') . ' ' . $message['location']['latitude'] . ',' . $message['location']['longitude'])
                : null,
            default => $message[$type]['caption'] ?? null,
        };
    }
}

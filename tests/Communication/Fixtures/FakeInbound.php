<?php

declare(strict_types=1);

namespace Tests\Communication\Fixtures;

use Illuminate\Http\Request;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * The fake vendor's webhook: WhatsApp messages and delivery statuses in one payload, the way Meta
 * sends them, signed with a header the fake vendor alone knows.
 */
final class FakeInbound implements InboundSource
{
    public const string SIGNATURE = 'fake-signature';

    /** @var list<string> */
    public array $downloaded = [];

    /** @var list<array<string, mixed>> */
    public array $statuses = [];

    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        return $request->header('X-Fake-Signature') === self::SIGNATURE;
    }

    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        return array_map(
            fn (array $message): InboundMessage => new InboundMessage(
                provider: FakeProvider::slug(),
                channel: ChannelTypeEnum::WHATSAPP,
                inboundIdentity: (string) $message['to'],
                from: (string) $message['from'],
                providerMessageId: (string) $message['id'],
                conversationSlug: 'fake-whatsapp-' . preg_replace('/\D/', '', (string) $message['from']),
                messageVerb: 'fake-whatsapp',
                messageSlug: 'fake-' . $message['id'],
                text: $message['text'] ?? null,
                raw: $message,
                attachments: array_map(
                    fn (array $file): InboundAttachment => new InboundAttachment($file['url'], $file['type']),
                    $message['files'] ?? []
                )
            ),
            $payload['messages'] ?? []
        );
    }

    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        if (! isset($payload['statuses'])) {
            return null;
        }

        array_push($this->statuses, ...$payload['statuses']);

        return ['recorded' => count($payload['statuses'])];
    }

    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        $this->downloaded[] = $attachment->url;

        $file = new Filesystem();
        $file->apps_id = $message->apps_id;
        $file->companies_id = $message->companies_id;
        $file->users_id = $message->users_id;
        $file->name = 'photo.jpg';
        $file->path = 'files/inbound/' . uniqid() . '.jpg';
        $file->url = $attachment->url;
        $file->file_type = 'jpg';
        $file->size = '1024';
        $file->is_deleted = 0;
        $file->saveOrFail();

        return $file;
    }

    public function burstPolicy(InboundMessage $inbound, Companies $company): BurstPolicy
    {
        return new BurstPolicy(
            correlationKeys: ['fake:' . $inbound->from],
            chainIdleSeconds: 15,
            closeIdleSeconds: 15,
            maxSeconds: 90,
            jitterSeconds: 0,
        );
    }

    public function burstHandler(): string
    {
        return FlushInboundBurstAction::class;
    }

    public function answersHelpItself(): bool
    {
        return false;
    }
}

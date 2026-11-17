<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Contracts\Concerns\HasInboundDefaults;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\Communication\Fixtures\FakeInbound;

/**
 * The fake vendor, noting what the pipeline had already done to a message when its media was asked
 * for. Needs a faked queue.
 */
final class RecordingInbound implements InboundSource
{
    use HasInboundDefaults;

    /** @var list<array{parent_id: int|null, closes_armed: int}> */
    public array $atDownload = [];

    public function __construct(
        private readonly FakeInbound $vendor,
        private readonly bool $bursts = true
    ) {
    }

    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        return $this->vendor->authenticate($request, $receiver);
    }

    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        return $this->vendor->messages($payload, $receiver);
    }

    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        $parentId = $message->fresh()->parent_id;

        $this->atDownload[] = [
            'parent_id' => $parentId !== null ? (int) $parentId : null,
            'closes_armed' => Queue::pushed(FlushMessageBurstJob::class)->count(),
        ];

        return $this->vendor->downloadAttachment($message, $attachment);
    }

    public function burstPolicy(InboundMessage $inbound, Companies $company): ?BurstPolicy
    {
        return $this->bursts ? $this->vendor->burstPolicy($inbound, $company) : null;
    }
}

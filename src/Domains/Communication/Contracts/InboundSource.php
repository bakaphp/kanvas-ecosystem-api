<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Illuminate\Http\Request;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Support\BurstHandler;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * A provider's side of the one communication webhook: proving a request came from it, reading what
 * the payload carries, and the few things about a received message only it knows. Everything else
 * is the shared pipeline's.
 */
interface InboundSource
{
    /**
     * The vendor's own signature, checked before anything is stored. A request this says no to is
     * never processed.
     */
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool;

    /**
     * @return list<InboundMessage> the messages the payload carries; none for a status-only payload
     */
    public function messages(array $payload, ReceiverWebhook $receiver): array;

    /**
     * Records the delivery statuses the payload carries.
     *
     * @return array<string, mixed>|null what was recorded, or null when it carries none
     */
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array;

    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem;

    /**
     * How a flurry from one person collapses into one turn. Null for a medium with real threading
     * (email), which is announced as it arrives.
     */
    public function burstPolicy(InboundMessage $inbound, Companies $company): ?BurstPolicy;

    /**
     * @return class-string<BurstHandler>
     */
    public function burstHandler(): string;

    /**
     * The provider answers a HELP itself with the carrier's advisory, so an agent turn on top would
     * be a second message nobody asked for.
     */
    public function answersHelpItself(): bool;
}

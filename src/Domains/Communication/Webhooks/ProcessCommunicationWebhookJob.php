<?php

declare(strict_types=1);

namespace Kanvas\Communication\Webhooks;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Contracts\VerifiesEndpoint;
use Kanvas\Communication\Messages\Actions\ReceiveMessageAction;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use LogicException;
use Override;

/**
 * The one webhook for every messaging provider. The receiver names its provider; that provider proves
 * the request is its own, then says what the payload carries — messages go through the shared inbound
 * pipeline, delivery statuses to the ledger. The provider is read from the receiver, never guessed
 * from the request: a header anyone can send must not choose which signature is checked.
 */
#[WorkflowAction(
    name: 'Communication Webhook',
    description: 'Receiver for every messaging provider (SMS, email, WhatsApp): checks the provider\'s '
        . 'signature, files inbound messages on the person and their conversation, and records delivery '
        . 'statuses. This is how messages ARRIVE — it does not reply. Created by Communication setup; '
        . 'not something to wire by hand.',
)]
class ProcessCommunicationWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $source = static::sourceFor($receiver);

        return $source !== null && $source->authenticate($request, $receiver);
    }

    #[Override]
    public static function verificationChallenge(Request $request, ReceiverWebhook $receiver): ?string
    {
        $source = static::sourceFor($receiver);

        return $source instanceof VerifiesEndpoint ? $source->challenge($request, $receiver) : null;
    }

    #[Override]
    public function execute(): array
    {
        $source = static::sourceFor($this->receiver)
            ?? throw new LogicException('Receiver ' . $this->receiver->getId() . ' names no communication provider.');
        $payload = $this->webhookRequest->payload ?? [];

        $statuses = $source->recordStatuses($payload, $this->receiver);
        $received = [];

        // One payload can carry numbers of several apps, and each message switches to its own.
        foreach ($source->messages($payload, $this->receiver) as $inbound) {
            try {
                array_push($received, ...new ReceiveMessageAction($this->receiver, $inbound, $source)->execute());
            } finally {
                $this->actAsReceiver();
            }
        }

        if ($statuses === null) {
            return $received;
        }

        if (($statuses['status'] ?? null) === 422) {
            $this->failedReturnHttpCode = 422;
        }

        return $received === [] ? $statuses : [...$received, 'statuses' => $statuses];
    }

    /**
     * The provider the receiver was created for.
     */
    protected static function providerOf(ReceiverWebhook $receiver): ?string
    {
        return $receiver->configuration['communication']['provider'] ?? null;
    }

    private static function sourceFor(ReceiverWebhook $receiver): ?InboundSource
    {
        $provider = static::providerOf($receiver);

        if ($provider === null) {
            return null;
        }

        try {
            return new ProviderRegistry()->inbound($provider);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\WebPos\Services\ResultMapperService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfDocumentLookup;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * The payload is the same JSON GetResultFe returns, so it goes through the same mapper; the poll and the
 * webhook can never disagree on what a status means.
 *
 * systemRef is the attempt's `system_ref`, so the answer lands on exactly the row that was sent — a late answer
 * about a superseded number never touches the document's current one. WebPOS may deliver the same result
 * twice, or a late "in process" after the final answer; ApplyFiscalAnswerAction dedupes and never steps back.
 */
#[WorkflowAction(
    name: 'WebPOS e-CF Result Webhook',
    description: 'Receiver for WebPOS e-CF results: finds the Scribe invoice, bill or expense by its systemRef and '
        . 'records the DGII outcome (accepted, accepted with observations, rejected) with its trackId, QR and legends. '
        . 'Inbound only; a repeated or out-of-order delivery is ignored.',
    integration: IntegrationsEnum::WEBPOS,
)]
class WebPosResultWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $systemRef = trim((string) ($payload['systemRef'] ?? ''));

        if ($systemRef === '') {
            return ['message' => 'No systemRef in payload; nothing to do.'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $fiscal = EcfDocumentLookup::bySystemRef($app, $company, $systemRef);

        if ($fiscal === null) {
            return ['message' => "No fiscal document with systemRef {$systemRef} in this company."];
        }

        // A late answer about an earlier attempt (the rejection a resend is correcting) must not overwrite the
        // attempt now in flight.
        $attempt = EcfDocumentLookup::attemptBySystemRef($app, $company, $systemRef);
        $latest = $fiscal->latestSubmission;

        if ($attempt !== null && $latest !== null && $attempt->getKey() !== $latest->getKey()) {
            return [
                'message' => "Answer about attempt {$attempt->attempt}, superseded by attempt {$latest->attempt}; not applied.",
                'fiscal_document_id' => $fiscal->getId(),
            ];
        }

        $reference = [
            'fiscal_document_id' => $fiscal->getId(),
            'document_type' => class_basename($fiscal->entity_type),
            'document_id' => $fiscal->entity_id,
        ];

        // A malformed result or one about another e-NCF is WebPOS's data problem, not ours to page about.
        try {
            new ApplyFiscalAnswerAction(
                fiscalDocument: $fiscal,
                answer: ResultMapperService::toSubmission($payload, $fiscal->number),
            )->execute();
        } catch (ValidationException $e) {
            return ['message' => $e->getMessage(), ...$reference];
        }

        return [
            'message' => 'e-CF result applied.',
            ...$reference,
            'status' => $fiscal->refresh()->status->toEcfStatus()->value,
        ];
    }

    /**
     * WebPOS's webhook signing is not documented yet. Until it is, a receiver can carry a shared `secret`
     * that WebPOS sends back as a bearer token or `?token=`; without one, the receiver uuid is the only key.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = (string) ($receiver->configuration['secret'] ?? '');

        if ($secret === '') {
            return true;
        }

        $sent = (string) ($request->bearerToken() ?? $request->query('token', ''));

        return $sent !== '' && hash_equals($secret, $sent);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\FiscalNumberHeldElsewhereException;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalPayloadService;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * A waiting issued e-CF is asked of the provider again and the answer goes through ApplyFiscalAnswerAction,
 * the same idempotent path the poll job and the webhooks use, so a status read can settle an e-CF but never
 * contradict a final answer already recorded.
 */
#[AgentTool(name: 'e-CF Status', category: 'accounting')]
class EcfStatusTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use ResolvesInvoiceForTool;
    use TrackByInputs;

    private const array ISSUED_KEYS = [
        'encf', 'type', 'status', 'provider', 'provider_ref', 'messages', 'security_code', 'signed_at', 'qr_url',
        'expires_at', 'submitted_at', 'poll_attempts', 'poll_exhausted', 'burned', 'number_consumed', 'action_required',
        'needs_review', 'updated_at',
    ];

    private const array RECEIVED_KEYS = [
        'encf', 'type', 'vendor_rnc', 'provider', 'security_code', 'signed_at', 'stamp_url', 'references',
        'commercial_response',
    ];

    protected string $name = 'ecf_status';

    protected ?string $description = 'Where an electronic invoice (e-CF) stands at DGII. With invoice_id: the e-NCF, status '
        . '(waiting / accepted / accepted_with_observations / rejected / cancelled), DGII messages and security code of an e-CF we issued; '
        . 'a waiting one is checked with the provider again. With bill_id: the vendor e-CF a bill came from and whether '
        . 'we already sent its commercial response (respond_to_vendor_ecf). Read-only for documents already decided. '
        . 'The status changes outside this conversation: whenever you are asked where an e-CF stands, call this tool, '
        . 'never repeat an earlier result, and never say you checked unless you called it this turn.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'An invoice or credit note we issued. Give this or bill_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'A bill that came in as a vendor e-CF. Give this or invoice_id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $invoice_id = null, ?int $bill_id = null): array
    {
        if ($invoice_id !== null) {
            return $this->issued($invoice_id);
        }

        if ($bill_id !== null) {
            return $this->received($bill_id);
        }

        return $this->invalidArgs('Give invoice_id (an e-CF we issued) or bill_id (a vendor e-CF).');
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(int $invoiceId): array
    {
        $invoice = $this->resolveInvoice($invoiceId);

        if (is_array($invoice)) {
            return $this->notFound(['message' => "No invoice with id {$invoiceId} for this company. Use find_invoice to get the id."]);
        }

        $fiscal = EcfCompliance::current($invoice);

        if ($fiscal === null) {
            return $this->noop(
                ['invoice_id' => $invoice->getId(), 'is_ecf' => false],
                'This invoice is not an electronic invoice (no e-NCF was assigned). Say so; do not retry.'
            );
        }

        $refreshed = false;
        $refreshError = null;
        $heldElsewhere = $fiscal->isHeldElsewhere();

        if (! $heldElsewhere && ($fiscal->status === FiscalDocumentStatusEnum::DRAFT || $fiscal->status->isOutstanding())) {
            try {
                $answer = new EcfProviderResolver()->resolveFor($fiscal)
                    ->fetchResult(FiscalPayloadService::forFiscal($fiscal));

                new ApplyFiscalAnswerAction(fiscalDocument: $fiscal, answer: $answer)->execute();
                $invoice->refresh();
                $refreshed = true;
            } catch (FiscalNumberHeldElsewhereException $e) {
                $fiscal->flagNumberHeldElsewhere($e->getMessage());
                $heldElsewhere = true;
            } catch (ValidationException $e) {
                // The provider answered, with something other than a result (no record of the document yet).
                $refreshError = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $refreshError = 'The provider could not be reached or answered with an error, so this is the last status we recorded.';
            }
        }

        $result = [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'is_ecf' => true,
            'checked_with_provider' => $refreshed,
            'provider_error' => $refreshError,
            ...array_intersect_key(EcfCompliance::read($invoice), array_flip(self::ISSUED_KEYS)),
        ];

        if ($heldElsewhere) {
            return $this->ok(
                [...$result, 'status' => 'number_used_elsewhere', 'provider_error' => $fiscal->refresh()->authorityValue('submission_error')],
                guidance: 'This e-NCF belongs to another document at the provider (issued outside Kanvas), so this invoice '
                    . 'never reached DGII under it and waiting will not change that. Say so plainly. It has to be voided and '
                    . 'issued again under a new number. Do not call ecf_status again for it.'
            );
        }

        return $this->ok($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function received(int $billId): array
    {
        $bill = $this->resolveBillOrError($billId);
        if (is_array($bill)) {
            return $bill;
        }

        /** @var Bill $bill */
        if (! ReceivedEcfCompliance::isReceivedEcf($bill)) {
            return $this->noop(
                ['bill_id' => $bill->getId(), 'is_ecf' => false],
                'This bill did not come in as a vendor e-CF, so there is no DGII document to check. Do not retry.'
            );
        }

        return $this->ok([
            'bill_id' => $bill->getId(),
            'is_ecf' => true,
            'answered' => ReceivedEcfCompliance::commercialResponse($bill) !== null,
            ...array_intersect_key(ReceivedEcfCompliance::read($bill), array_flip(self::RECEIVED_KEYS)),
        ]);
    }
}

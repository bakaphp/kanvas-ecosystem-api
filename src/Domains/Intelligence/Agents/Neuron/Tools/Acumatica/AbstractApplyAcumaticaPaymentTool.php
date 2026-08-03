<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use RuntimeException;
use Throwable;

/**
 * Shared body for the AP/AR "apply a payment to an already-pushed document, then push the payment to
 * Acumatica" tools. The two flows are identical bar the document type (Bill vs Invoice), the allocate
 * action, and the ref custom field — so the control flow, tenant lookup, guards, and result shape live
 * here and each concrete tool supplies the differences via the hooks below.
 *
 * Naming (LLM param, result keys, reasons, prose) is all derived from noun() so 'bill'/'invoice' can't
 * drift between the schema and the response.
 */
abstract class AbstractApplyAcumaticaPaymentTool extends Tool
{
    use HasKanvasContext;

    /** 'bill' | 'invoice' — drives the {noun}_id param, {noun}_id/{noun}_ref result keys, and messages. */
    abstract protected function noun(): string;

    /** Acumatica ref custom-field key on the document (BILL_REF / INVOICE_REF). */
    abstract protected function refCustomField(): string;

    abstract protected function resolveDocument(int $id): ?BaseModel;

    abstract protected function allocatePayment(BaseModel $document, float $amount, string $reference): Payment;

    /**
     * @return array{remaining_balance: float, document_status: string}
     */
    abstract protected function refreshedState(BaseModel $document): array;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        $noun = $this->noun();

        return [
            new ToolProperty(
                name: $noun . '_id',
                type: PropertyType::INTEGER,
                description: "The Kanvas {$noun} id to apply the payment against.",
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: "Payment amount. Must not exceed the {$noun}'s remaining balance.",
                required: true,
            ),
            new ToolProperty(
                name: 'reference',
                type: PropertyType::STRING,
                description: 'Payment reference (check number, wire ref, etc). Acumatica rejects an empty one.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function applyPayment(int $id, float $amount, string $reference): array
    {
        $noun = $this->noun();
        $idKey = $noun . '_id';

        $document = $this->resolveDocument($id);

        if ($document === null) {
            return [
                'applied' => false,
                'reason' => $noun . '_not_found',
                'message' => "No {$noun} with id {$id} for this app/company.",
            ];
        }

        $ref = (string) $document->get($this->refCustomField(), '');

        if ($ref === '') {
            return [
                'applied' => false,
                'reason' => $noun . '_not_pushed',
                'message' => ucfirst($noun) . " {$id} hasn't been pushed to Acumatica yet — push it before applying a payment.",
            ];
        }

        try {
            $payment = $this->allocatePayment($document, $amount, $reference);
        } catch (RuntimeException $e) {
            return [
                'applied' => false,
                'reason' => 'allocation_failed',
                'message' => $e->getMessage(),
            ];
        }

        try {
            $paymentRef = new PushPaymentToAcumaticaAction($payment)->execute();
        } catch (Throwable $e) {
            return [
                'applied' => true,
                'pushed' => false,
                $idKey => $document->getId(),
                'reason' => 'push_failed',
                'message' => 'Payment recorded in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'applied' => true,
            'pushed' => true,
            $idKey => $document->getId(),
            $noun . '_ref' => $ref,
            'amount' => $amount,
            'payment_ref' => $paymentRef,
            ...$this->refreshedState($document),
        ];
    }
}

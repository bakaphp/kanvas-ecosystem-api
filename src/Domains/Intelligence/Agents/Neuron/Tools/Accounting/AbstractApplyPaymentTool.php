<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use RuntimeException;

/**
 * Shared body for applying AP/AR payments. Kanvas owns payment allocation; configured workflows
 * handle any external synchronization after the ledger transaction completes.
 *
 * Naming (LLM param, result keys, reasons, prose) is derived from noun() so 'bill'/'invoice' can't drift.
 */
abstract class AbstractApplyPaymentTool extends Tool
{
    use HasKanvasContext;

    /** 'bill' | 'invoice' — drives the document id parameter/result key and messages. */
    abstract protected function noun(): string;

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
                description: 'Payment reference (check number, wire ref, etc).',
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

        try {
            $payment = $this->allocatePayment($document, $amount, $reference);
        } catch (RuntimeException $e) {
            return [
                'applied' => false,
                'reason' => 'allocation_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'applied' => true,
            $idKey => $document->getId(),
            'payment_id' => $payment->getId(),
            'amount' => $amount,
            'payment_ref' => $reference,
            ...$this->refreshedState($document),
            'next' => 'Payment allocated in Kanvas. Any external synchronization is handled by configured '
                . 'workflow activities.',
        ];
    }
}

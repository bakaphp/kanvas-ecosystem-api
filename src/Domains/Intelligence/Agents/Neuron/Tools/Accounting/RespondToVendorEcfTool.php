<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RespondToReceivedEcfAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Provider-neutral: RespondToReceivedEcfAction asks the company's e-CF provider (WebPOS, Alanube) to send it,
 * so the tool never knows which one it is.
 */
#[AgentTool(name: 'Respond To Vendor e-CF', category: 'accounting')]
class RespondToVendorEcfTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use TrackByInputs;

    private const array DECISIONS = ['accept', 'reject'];

    protected string $name = 'respond_to_vendor_ecf';

    protected ?string $description = 'Sends DGII\'s commercial response for a vendor electronic invoice (e-CF) that came in '
        . 'as a bill: accept it, or reject it with a reason. DGII forwards it to the vendor, and each document takes '
        . 'only one answer, so check the bill first (lines, amounts, vendor RNC, that it is really ours). Reject '
        . 'only a document we do not owe or that is wrong; the reason is sent to the vendor. Only bills that came in '
        . 'as a received e-CF (find_bill shows source e_invoice) can be answered.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The bill created from the vendor e-CF, from find_bill or list_open_bills.',
                required: true,
            ),
            new ToolProperty(
                name: 'decision',
                type: PropertyType::STRING,
                description: '"accept" or "reject".',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Required to reject: why, in words the vendor will read. Ignored on accept.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id, string $decision, ?string $reason = null): array
    {
        return $this->oncePerTurn(
            ['bill_id' => $bill_id, 'decision' => $decision],
            fn (): array => $this->respond($bill_id, $decision, $reason),
            note: 'This e-CF was already answered earlier in this turn. Nothing was sent now; report the answer shown here.',
            rememberFailures: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function respond(int $billId, string $decision, ?string $reason): array
    {
        $decision = strtolower(trim($decision));

        if (! in_array($decision, self::DECISIONS, true)) {
            return $this->invalidArgs("decision must be \"accept\" or \"reject\" (got \"{$decision}\").", ['sent' => false]);
        }

        $bill = $this->resolveBillOrError($billId);
        if (is_array($bill)) {
            return $bill;
        }

        try {
            $response = new RespondToReceivedEcfAction(
                bill: $bill,
                accepted: $decision === 'accept',
                reason: $decision === 'reject' ? $reason : null,
                user: $this->user,
            )->execute();
        } catch (ValidationException $e) {
            return $this->denied($e->getMessage(), ['sent' => false, 'bill_id' => $bill->getId()]);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'The provider did not take the commercial response, so nothing reached DGII.',
                ['sent' => false, 'bill_id' => $bill->getId()],
                'Say the answer was NOT sent; it can be retried later.',
            );
        }

        return $this->ok([
            'sent' => true,
            'bill_id' => $bill->getId(),
            'decision' => $decision,
            'commercial_response' => $response,
        ]);
    }
}

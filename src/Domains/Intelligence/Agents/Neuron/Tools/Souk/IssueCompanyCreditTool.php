<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Souk;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Discounts\Actions\CreateDiscountAction;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountConditionData;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountData;
use Kanvas\Souk\Discounts\Enums\DiscountTypeEnum;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

#[AgentTool(name: 'Issue Company Credit', category: 'commerce')]
class IssueCompanyCreditTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'issue_company_credit',
            description: 'Issue a monetary credit to a client company that will be applied automatically '
                . 'to its next order (e.g. because items on a previous order were unavailable). Provide the '
                . 'client company (id or exact name), the amount and the reason; optionally the order number '
                . 'that caused it. Returns issued=false with a reason when the company cannot be found.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Credit amount in the order currency. Must be greater than zero.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the credit is issued, e.g. "2 units of SKU X unavailable on order 1042".',
                required: true,
            ),
            new ToolProperty(
                name: 'company_id',
                type: PropertyType::INTEGER,
                description: 'Id of the client company receiving the credit. Use this or company_name.',
                required: false,
            ),
            new ToolProperty(
                name: 'company_name',
                type: PropertyType::STRING,
                description: 'Exact name of the client company receiving the credit. Prefer company_id when known.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_order_number',
                type: PropertyType::STRING,
                description: 'Optional order number the credit compensates for; recorded in the description.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        float $amount,
        string $reason,
        ?int $company_id = null,
        ?string $company_name = null,
        ?string $source_order_number = null,
    ): array {
        if ($amount <= 0) {
            return ['issued' => false, 'reason' => 'The credit amount must be greater than zero.'];
        }

        if ($company_id === null && trim((string) $company_name) === '') {
            return ['issued' => false, 'reason' => 'Provide company_id or company_name for the client receiving the credit.'];
        }

        $client = $this->resolveClient($company_id, $company_name);

        if ($client === null) {
            return ['issued' => false, 'reason' => 'No company with that id or name belongs to this app.'];
        }

        $description = $reason;

        if ($source_order_number !== null) {
            $order = Order::fromApp($this->app)->fromCompany($client)->where('order_number', $source_order_number)->first();
            $description .= $order !== null
                ? " (order {$order->order_number})"
                : " (order {$source_order_number}, not found for this company)";
        }

        try {
            $credit = new CreateDiscountAction(
                $this->app,
                $client,
                new DiscountData(
                    name: 'Credit: ' . mb_substr($reason, 0, 80),
                    description: $description,
                    discount_type_id: DiscountType::getByName(DiscountTypeEnum::AUTO_APPLIED_CREDIT->label())->getId(),
                    value: round($amount, 2),
                    conditions: DiscountConditionData::collect([], DataCollection::class),
                ),
            )->execute();
        } catch (ValidationException $e) {
            return ['issued' => false, 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['issued' => false, 'reason' => 'The credit could not be issued: ' . $e->getMessage()];
        }

        return [
            'issued' => true,
            'credit_id' => $credit->getId(),
            'company' => $client->name,
            'amount' => $credit->value,
            'next' => 'It will be applied automatically to this company\'s next order.',
        ];
    }

    private function resolveClient(?int $companyId, ?string $companyName): ?Companies
    {
        if ($companyId !== null) {
            try {
                $company = Companies::getById($companyId);
                CompaniesRepository::hasAccessToThisApp($company, $this->app);

                return $company;
            } catch (Throwable) {
                return null;
            }
        }

        if ($companyName !== null && trim($companyName) !== '') {
            return CompaniesRepository::getCompanyByNameAndApp(trim($companyName), $this->app);
        }

        return null;
    }
}

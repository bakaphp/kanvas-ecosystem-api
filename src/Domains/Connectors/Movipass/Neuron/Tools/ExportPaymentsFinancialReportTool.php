<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Traits\ResolvesMovipassReportScope;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\ExportPaymentsFinancialReportAction;
use Kanvas\Souk\Orders\DataTransferObject\PaymentsFinancialReport;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Movipass Payments Financial Report', category: 'commerce')]
class ExportPaymentsFinancialReportTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ResolvesMovipassReportScope;
    use TrackByInputs;

    protected string $name = 'movipass_export_payments_financial_report';

    protected ?string $description = 'Generates the payments financial report as a downloadable Excel and returns its link: '
        . 'a payment summary sheet (gross, fees and net collected, digital vs physical), a daily payments sheet and '
        . 'a per-transaction detail sheet. Payments count on the date they were paid, the same as the payments '
        . 'dashboard. Use for "send me the financial report", "export the payments of October", "the collection '
        . 'report for this lot". For figures in the chat instead of a file, use movipass_order_payment_stats.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'First payment date to include, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Last payment date to include, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(
                name: 'order_type',
                type: PropertyType::STRING,
                description: 'Restrict to one operation: movipass (parking), paso_rapido (tag recharges), impound_lot or roadside_assistance. Omit for all of them together.',
                required: false,
                enum: array_column(OrderTypeEnum::cases(), 'value'),
            ),
            new ToolProperty(
                name: 'payment_channel',
                type: PropertyType::STRING,
                description: 'Restrict to digital (card) or physical (everything else) payments. Omit for both.',
                required: false,
                enum: array_column(PaymentChannelEnum::cases(), 'value'),
            ),
            new ToolProperty(name: 'variant_id', type: PropertyType::INTEGER, description: 'Restrict to one location or service sold. Omit for all.', required: false),
            new ToolProperty(name: 'provider_company_id', type: PropertyType::INTEGER, description: 'Restrict to one provider company. Ignored for a provider agent, which is always scoped to its own company.', required: false),
            new ToolProperty(name: 'timezone', type: PropertyType::STRING, description: 'IANA timezone the days are cut in, e.g. "America/Santo_Domingo". Defaults to the company timezone.', required: false),
        ];
    }

    public function __invoke(
        ?string $since = null,
        ?string $until = null,
        ?string $order_type = null,
        ?string $payment_channel = null,
        ?int $variant_id = null,
        ?int $provider_company_id = null,
        ?string $timezone = null,
    ): array {
        return $this->oncePerTurn(
            [
                'since' => $since,
                'until' => $until,
                'order_type' => $order_type,
                'payment_channel' => $payment_channel,
                'variant_id' => $variant_id,
                'provider_company_id' => $provider_company_id,
                'timezone' => $timezone,
            ],
            function () use ($since, $until, $order_type, $payment_channel, $variant_id, $provider_company_id, $timezone): array {
                if (! $this->hasTenantContext()) {
                    return $this->tenantContextMissingError('financial report');
                }

                $user = $this->contextUser();

                if ($this->isCustomerSurface() || $user === null) {
                    return $this->error('The financial report is internal to the team and cannot be generated in this conversation.');
                }

                $type = $order_type !== null ? OrderTypeEnum::tryFrom(strtolower(trim($order_type))) : null;

                if ($order_type !== null && $type === null) {
                    return $this->unknownOrderType();
                }

                $channel = $payment_channel !== null ? PaymentChannelEnum::tryFrom(strtolower(trim($payment_channel))) : null;

                if ($payment_channel !== null && $channel === null) {
                    return $this->error('payment_channel must be one of: ' . implode(', ', array_column(PaymentChannelEnum::cases(), 'value')) . '.');
                }

                $providerScope = $this->providerCompanyScope($provider_company_id);

                if ($providerScope === null) {
                    return $this->providerScopeUnavailable();
                }

                $timezone = $this->reportTimezone($timezone);

                try {
                    $startDate = Carbon::parse($since ?? 'today', $timezone)->toDateString();
                    $endDate = Carbon::parse($until ?? 'today', $timezone)->toDateString();
                } catch (Throwable) {
                    return $this->error('since and until must be dates in YYYY-MM-DD format.');
                }

                if ($startDate > $endDate) {
                    return $this->error('since must be on or before until.');
                }

                try {
                    $variant = $variant_id !== null ? Variants::getById($variant_id, $this->app) : null;
                } catch (ModelNotFoundException) {
                    return $this->error("No location or service with id {$variant_id} exists in this app.");
                }

                try {
                    return new ExportPaymentsFinancialReportAction(
                        new PaymentsFinancialReport(
                            app: $this->app,
                            user: $user,
                            startDate: $startDate,
                            endDate: $endDate,
                            timezone: $timezone,
                            orderTypeNames: $type !== null ? [$type->value] : [],
                            variant: $variant,
                            channel: $channel,
                            providerCompanyIds: $providerScope,
                        ),
                    )->execute();
                } catch (Throwable $e) {
                    report($e);

                    return [
                        'status' => 'error',
                        'success' => false,
                        'message' => 'The report could not be generated right now. Tell the user to try again later.',
                    ];
                }
            },
            rememberFailures: false,
        );
    }

    private function error(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
        ];
    }
}

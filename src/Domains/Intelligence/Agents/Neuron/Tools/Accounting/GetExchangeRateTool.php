<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Money;
use Baka\Support\Number;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Kanvas\Scribe\FxRates\Services\MarketRateService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * The official rate on file wins over a live one: it is the table a fiscal document freezes its declared rate
 * from, so the number quoted is the number declared.
 */
#[AgentTool(name: 'Get Exchange Rate', category: 'accounting')]
class GetExchangeRateTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    public const string KIND_OFFICIAL = 'official';
    public const string KIND_MARKET = 'market';
    private const int RATE_PRECISION = 10;
    private const int DISPLAY_DECIMALS = 8;
    private const string NEVER_ESTIMATE = 'Do not estimate a rate or quote one from memory, and do not call again with '
        . 'the same currencies and date.';

    protected string $name = 'get_exchange_rate';

    protected ?string $description = 'Returns the exchange rate between any two currencies, in either direction, and '
        . 'converts an amount when one is given. When an official central-bank rate is on file for the pair (for '
        . 'example USD and DOP, the rate a foreign-currency electronic invoice declares to the tax authority) it '
        . 'answers with that one, for today or another date. Any other pair gets the live mid-market rate, for today '
        . 'only. The result says which kind it is (official or market) and when it was published. Use it whenever '
        . 'the user asks for an exchange rate, the tasa or tipo de cambio, or what an amount is worth in another '
        . 'currency. It only reads: it never changes the rate on an invoice, bill or fiscal document. Never quote a '
        . 'rate from memory.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'from_currency',
                type: PropertyType::STRING,
                description: 'ISO currency code to convert from, e.g. USD.',
                required: true,
            ),
            new ToolProperty(
                name: 'to_currency',
                type: PropertyType::STRING,
                description: 'ISO currency code to convert to, e.g. DOP. Defaults to the company\'s local fiscal '
                    . 'currency.',
                required: false,
            ),
            new ToolProperty(
                name: 'date',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) the rate is wanted for. Defaults to today. Any other date is '
                    . 'answered only from official rates on file.',
                required: false,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'An amount in from_currency to convert into to_currency.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $from_currency,
        ?string $to_currency = null,
        ?string $date = null,
        ?float $amount = null
    ): array {
        $from = strtoupper(trim($from_currency));
        $to = strtoupper(
            Str::trimToNull($to_currency) ?? (string) FiscalRegimes::forCompany($this->company)?->currency()
        );

        if ($to === '') {
            return $this->invalidArgs(
                'to_currency is required: this company has no local fiscal currency to default to.',
                guidance: 'Ask which currency to convert to, then call again with to_currency.'
            );
        }

        if (! Money::isCurrencyCode($from) || ! Money::isCurrencyCode($to) || $from === $to) {
            return $this->invalidArgs(
                "from_currency and to_currency must be two different 3-letter ISO codes; got \"{$from}\" and \"{$to}\"."
            );
        }

        $date = Str::trimToNull($date);
        if ($date !== null && DateHelper::parseYmd($date) === null) {
            return $this->invalidArgs("date \"{$date}\" is not a date; use YYYY-MM-DD.");
        }

        if ($amount !== null && $amount < 0) {
            return $this->invalidArgs('amount must be 0 or more.');
        }

        // The company's own today: the server runs on UTC, a day ahead of an evening in the Americas.
        $today = Carbon::today($this->company->getTimezone())->toDateString();
        $requested = $date ?? $today;
        $pair = [
            'from_currency' => $from,
            'to_currency' => $to,
            'requested_date' => $requested,
        ];
        $quote = $this->officialQuote($from, $to, Carbon::parse($requested));

        if ($quote === null && $requested !== $today) {
            return $this->notFound(
                [
                    ...$pair,
                    'message' => "No official {$from}/{$to} rate is on file for {$requested} or the "
                        . FxRateService::MAX_AGE_DAYS . ' days before it, and a live market rate exists for today '
                        . 'only.',
                ],
                'Say that no rate is on file for that date, and offer today\'s rate instead. ' . self::NEVER_ESTIMATE
            );
        }

        if ($quote === null) {
            try {
                $quote = $this->marketQuote($from, $to);
            } catch (Throwable $e) {
                report($e);

                return $this->failed(
                    "The live {$from}/{$to} rate could not be looked up right now.",
                    $pair,
                    'Say the rate could not be looked up right now. ' . self::NEVER_ESTIMATE
                );
            }
        }

        if ($quote === null) {
            return $this->notFound(
                [
                    ...$pair,
                    'message' => "There is no {$from}/{$to} quote right now. Check that both are real ISO currency "
                        . 'codes.',
                ],
                self::NEVER_ESTIMATE
            );
        }

        $converted = $amount !== null ? round($amount * $quote['rate'], 2) : null;

        return $this->ok([
            ...$pair,
            ...$quote,
            'amount' => $amount,
            'converted_amount' => $converted,
            'message' => $this->message(
                $quote,
                $from,
                $to,
                $amount,
                $converted
            ),
        ]);
    }

    /**
     * The table stores a pair one way (USD/DOP); the other direction is the same published rate inverted.
     *
     * @return array{kind: string, rate: float, as_of: string, source: string}|null
     */
    private function officialQuote(string $from, string $to, Carbon $day): ?array
    {
        $service = new FxRateService();
        $direct = $service->rateOn(
            $this->app,
            $from,
            $to,
            $day
        );
        $published = $direct ?? $service->rateOn(
            $this->app,
            $to,
            $from,
            $day
        );

        if ($published === null || $published->rate <= 0) {
            return null;
        }

        $type = $published->metadata['type'] ?? null;

        return [
            'kind' => self::KIND_OFFICIAL,
            'rate' => $direct !== null ? $published->rate : round(1 / $published->rate, self::RATE_PRECISION),
            'as_of' => $published->rate_date->toDateString(),
            'source' => $published->source . ($type !== null ? " ({$type})" : ''),
        ];
    }

    /**
     * @return array{kind: string, rate: float, as_of: string, source: string}|null
     */
    private function marketQuote(string $from, string $to): ?array
    {
        $rate = new MarketRateService()->latest($from, $to);

        return $rate === null ? null : [
            'kind' => self::KIND_MARKET,
            'rate' => $rate->rate,
            'as_of' => $rate->observedAt->toIso8601String(),
            'source' => $rate->provider . ' (mid-market)',
        ];
    }

    /**
     * @param array{kind: string, rate: float, as_of: string, source: string} $quote
     */
    private function message(
        array $quote,
        string $from,
        string $to,
        ?float $amount,
        ?float $converted
    ): string {
        $message = "1 {$from} = " . Number::withoutTrailingZeros($quote['rate'], self::DISPLAY_DECIMALS) . " {$to}";

        $message .= $quote['kind'] === self::KIND_OFFICIAL
            ? ", the official rate published {$quote['as_of']} ({$quote['source']}). It is the daily published rate on "
                . 'file, not a live market quote.'
            : ", the live mid-market rate as of {$quote['as_of']} ({$quote['source']}). It is a market reference, not "
                . 'an official central-bank rate, and no fiscal document declares it.';

        if ($amount !== null) {
            $message .= ' ' . number_format($amount, 2) . " {$from} = "
                . number_format((float) $converted, 2) . " {$to}.";
        }

        return $message;
    }
}

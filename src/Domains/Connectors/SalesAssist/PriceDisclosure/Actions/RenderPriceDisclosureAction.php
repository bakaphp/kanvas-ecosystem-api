<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject\VehiclePrice;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureChannelEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureFeeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\Models\Templates;

class RenderPriceDisclosureAction
{
    private const string TEMPLATE_PREFIX = 'ca_price_disclosure';

    public const string DEFAULT_TEMPLATE_NAME = 'ca_price_disclosure_default';

    /**
     * Approved default copy, used when the dealer has not uploaded its own template for the
     * channel and language. The wording is legal copy: change it only with an approved revision.
     */
    public const string DEFAULT_TEMPLATE = 'The vehicle total price before rebates or incentives is ${{ $ca_cars_total_price }}. '
        . 'Including the ${{ $documentation_fee }} documentation fee and ${{ $electronic_filing_charge }} electronic filing charge, '
        . 'the selling price is ${{ $pre_rebate_selling_price }}. '
        . 'The current advertised sale price is ${{ $ftc_actual_price }}, before government-required taxes and registration charges.';

    public function __construct(
        private readonly Lead $lead,
        private readonly VehiclePrice $price,
        private readonly PriceDisclosureChannelEnum $channel,
        private readonly string $language,
    ) {
    }

    public static function templateName(PriceDisclosureChannelEnum $channel, string $language): string
    {
        return sprintf('%s_%s_%s', self::TEMPLATE_PREFIX, $channel->value, strtolower($language));
    }

    /**
     * @return array{template: string, template_id: int|null, message: string, content_hash: string}
     */
    public function execute(): array
    {
        $template = $this->findTemplate();

        $message = new RenderTemplateAction($this->lead->app, $this->lead->company)->execute(
            $template?->name ?? self::DEFAULT_TEMPLATE_NAME,
            $this->templateVariables(),
            $template?->template ?? self::DEFAULT_TEMPLATE,
        );

        // Blade escapes for HTML; an SMS body is plain text, so "O'Brien" must not go out as O&#039;Brien.
        if ($this->channel === PriceDisclosureChannelEnum::SMS) {
            $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $message = trim($message);

        return [
            'template' => $template?->name ?? self::DEFAULT_TEMPLATE_NAME,
            'template_id' => $template?->getId(),
            'message' => $message,
            'content_hash' => hash('sha256', $message),
        ];
    }

    private function findTemplate(): ?Templates
    {
        $template = Templates::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->notDeleted()
            ->where('name', self::templateName($this->channel, $this->language))
            ->first();

        return $template instanceof Templates ? $template : null;
    }

    private function templateVariables(): array
    {
        $variant = $this->price->variant;
        $attributes = $variant->product->attributes()->get()->pluck('value', 'slug');
        $vehicleInterest = (array) ($this->lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value) ?? []);

        $year = $attributes['year'] ?? $vehicleInterest['year'] ?? $vehicleInterest['yearFrom'] ?? '';
        $make = $attributes['make'] ?? $vehicleInterest['make'] ?? '';
        $model = $attributes['model'] ?? $vehicleInterest['model'] ?? '';

        return [
            'first_name' => $this->lead->people->firstname ?: $this->lead->people->name,
            'dealership' => $this->lead->company->name,
            'year_make_model' => trim(implode(' ', array_filter([(string) $year, (string) $make, (string) $model]))),
            'stock_number' => (string) $variant->sku,
            'vin' => (string) ($vehicleInterest['vin'] ?? $variant->sku),
            'ca_cars_total_price' => self::money($this->price->caCarsTotalPrice),
            'pre_rebate_selling_price' => self::money($this->price->preRebateSellingPrice),
            'ftc_actual_price' => self::money($this->price->ftcActualPrice),
            'documentation_fee' => self::money($this->price->fee(PriceDisclosureFeeEnum::DOCUMENTATION_FEE)),
            'electronic_filing_charge' => self::money($this->price->fee(PriceDisclosureFeeEnum::ELECTRONIC_FILING_CHARGE)),
            'mandatory_dealer_fees' => self::money($this->price->mandatoryDealerFeesTotal()),
        ];
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }
}

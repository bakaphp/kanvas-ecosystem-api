<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject\VehiclePrice;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\Models\Templates;

class RenderPriceDisclosureAction
{
    private const string TEMPLATE_PREFIX = 'ca_price_disclosure';

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
     * @return array{template: string, template_id: int, message: string, content_hash: string}|null
     */
    public function execute(): ?array
    {
        $template = $this->findTemplate();

        if ($template === null) {
            return null;
        }

        $message = new RenderTemplateAction($this->lead->app, $this->lead->company)
            ->execute($template->name, $this->templateVariables(), $template->template);

        // Blade escapes for HTML; an SMS body is plain text, so "O'Brien" must not go out as O&#039;Brien.
        if ($this->channel === PriceDisclosureChannelEnum::SMS) {
            $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $message = trim($message);

        return [
            'template' => $template->name,
            'template_id' => $template->getId(),
            'message' => $message,
            'content_hash' => hash('sha256', $message),
        ];
    }

    /**
     * No app-level fallback on purpose: a legal disclosure has to be the dealer's own approved copy,
     * so a generic platform template counts as missing.
     */
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
            'ftc_actual_price' => self::money($this->price->ftcActualPrice),
            'mandatory_dealer_fees' => self::money($this->price->mandatoryDealerFeesTotal()),
        ];
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }
}

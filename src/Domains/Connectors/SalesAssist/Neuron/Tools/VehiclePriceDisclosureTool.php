<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Neuron\Tools;

use Baka\Support\Str;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\RenderPriceDisclosureAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\SuppressAgentReplyAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureChannelEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureReasonEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Services\VehiclePriceService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Vehicle Price Disclosure', category: 'crm')]
class VehiclePriceDisclosureTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public const string NAME = 'vehicle_price_disclosure';

    private const string DEFAULT_LANGUAGE = 'en';

    public function __construct()
    {
        parent::__construct(
            name: self::NAME,
            description: 'Returns the dealer-approved, legally required price disclosure message for a specific vehicle. '
                . 'You MUST call this tool before stating, quoting, estimating, or discussing any vehicle price, MSRP, fee, or discount. '
                . 'When the result has suppress=false, send the returned "message" text VERBATIM as your reply: never paraphrase, '
                . 'translate, round, reorder, or omit any amount or sentence, and never add a different price. '
                . 'When the result has suppress=true, do NOT mention any price and do NOT promise to check it later; '
                . 'the lead has already been handed off to a human. '
                . 'This is an internal operation: never expose the tool call, reason codes, or routing details to the customer.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'The channel the reply will be sent on. Allowed values only: "sms" or "email".',
                required: true,
            ),
            new ToolProperty(
                name: 'vin',
                type: PropertyType::STRING,
                description: 'VIN of the vehicle being discussed, when the customer or the context names one. '
                    . 'Leave empty to use the lead\'s vehicle of interest.',
                required: false,
            ),
            new ToolProperty(
                name: 'language',
                type: PropertyType::STRING,
                description: 'ISO 639-1 code of the language the customer is negotiating in (for example "en" or "es"). Defaults to "en".',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        string $channel,
        ?string $vin = null,
        ?string $language = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $disclosureChannel = PriceDisclosureChannelEnum::tryFrom(strtolower(trim($channel)));
        if ($disclosureChannel === null) {
            return $this->invalidArgs('Unsupported channel. Allowed values are "sms" or "email".');
        }

        $language = strtolower(Str::trimToNull($language) ?? self::DEFAULT_LANGUAGE);
        $priceService = new VehiclePriceService($lead);

        $variant = $priceService->resolveVariant($vin);
        if ($variant === null) {
            return $this->suppress(
                $lead,
                PriceDisclosureReasonEnum::VEHICLE_UNRESOLVED,
                'The vehicle referenced in the conversation could not be matched to a unit in inventory.',
            );
        }

        $price = $priceService->priceFor($variant);
        if ($price === null) {
            return $this->suppress(
                $lead,
                PriceDisclosureReasonEnum::PRICE_MISSING,
                "No approved price is available for vehicle {$variant->sku}.",
            );
        }

        $rendered = new RenderPriceDisclosureAction(
            $lead,
            $price,
            $disclosureChannel,
            $language,
        )->execute();
        if ($rendered === null) {
            return $this->suppress(
                $lead,
                PriceDisclosureReasonEnum::TEMPLATE_MISSING,
                sprintf(
                    'No approved price disclosure template "%s" exists for this dealer.',
                    RenderPriceDisclosureAction::templateName($disclosureChannel, $language),
                ),
            );
        }

        return $this->ok([
            'suppress' => false,
            'mode' => 'full_message',
            'message' => $rendered['message'],
            'vehicle_key' => $price->vehicleKey,
            'channel' => $disclosureChannel->value,
            'language' => $language,
            'template' => $rendered['template'],
            'content_hash' => $rendered['content_hash'],
            'price' => [
                'ca_cars_total_price' => $price->caCarsTotalPrice,
                'ftc_actual_price' => $price->ftcActualPrice,
                'mandatory_dealer_fees_total' => $price->mandatoryDealerFeesTotal(),
                'currency' => $price->currency,
                'source_system' => $price->sourceSystem,
                'source_version' => $price->sourceVersion,
            ],
        ], guidance: 'Send "message" exactly as written, with no changes, additions, or other prices.');
    }

    private function suppress(Lead $lead, PriceDisclosureReasonEnum $reason, string $detail): array
    {
        $handoff = new SuppressAgentReplyAction($lead, $reason, $detail)->execute();

        return $this->denied(
            $detail,
            [
                'suppress' => true,
                'reason_code' => $reason->value,
                'handoff' => $handoff,
            ],
            guidance: 'Do not state, estimate, or promise any price. A human will follow up with the customer.',
        );
    }
}

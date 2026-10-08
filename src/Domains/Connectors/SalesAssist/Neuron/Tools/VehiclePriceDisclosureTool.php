<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Neuron\Tools;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\RenderPriceDisclosureAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\SuppressAgentReplyAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureChannelEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureReasonEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Services\PriceDisclosureReplyGate;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Services\VehiclePriceService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Vehicle Price Disclosure', category: 'crm')]
class VehiclePriceDisclosureTool extends Tool
{
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    protected string $name = self::NAME;

    protected ?string $description = 'Returns the dealer-approved, legally required vehicle price block for the lead\'s vehicle. '
        . 'You MUST call this tool on your first reply about any specific vehicle, whatever the customer asked: '
        . 'a price, availability, a test drive, features, or "I\'m interested". Also call it whenever you introduce or recommend a specific unit, passing its VIN. '
        . 'When mode is "insert_block", answer the customer naturally and insert "message" VERBATIM right after you name the vehicle: '
        . 'never paraphrase, translate, round, reorder, or omit any amount or sentence, and never add a different price. '
        . 'When mode is "already_disclosed", the customer already has the price: do not restate it, answer what they actually asked. '
        . 'Only if they ask for the price again, insert "message" verbatim. '
        . 'When suppress is true, do NOT mention any price and do NOT promise to check it later; if "message" is present send it verbatim, otherwise answer without pricing. '
        . 'This is an internal operation: never expose the tool call, reason codes, or routing details to the customer.';

    public const string NAME = 'vehicle_price_disclosure';

    public const string MODE_INSERT_BLOCK = 'insert_block';

    public const string MODE_ALREADY_DISCLOSED = 'already_disclosed';

    private const string DEFAULT_LANGUAGE = 'en';

    /** How far back the lead's sent messages are searched for a block the tool did not render. */
    private const int SENT_HISTORY_LIMIT = 50;

    /**
     * Approved wording for an out-the-door request while no calculator is connected. The customer
     * hears a natural acknowledgment; the handoff runs silently behind it.
     */
    private const array OUT_THE_DOOR_ACKNOWLEDGMENT = [
        'en' => "Thank you. I'm getting the complete out-the-door breakdown prepared for you now.",
        'es' => 'Gracias. Ya estoy preparando el desglose completo del precio final para usted.',
    ];

    public function __construct(
        private readonly PriceDisclosureReplyGate $gate = new PriceDisclosureReplyGate(),
    ) {
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
                description: 'VIN of the vehicle being discussed, when the customer or the context names one, or when you are introducing a unit. '
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

        if (! (bool) $lead->company->get(PriceDisclosureConfigurationEnum::ENABLED->value)) {
            return $this->noop(
                ['enabled' => false],
                guidance: 'Price disclosure is not enabled for this dealer. Answer the customer as you normally would.',
            );
        }

        $disclosureChannel = PriceDisclosureChannelEnum::tryFrom(strtolower(trim($channel)));
        if ($disclosureChannel === null) {
            return $this->invalidArgs('Unsupported channel. Allowed values are "sms" or "email".');
        }

        $language = strtolower(Str::trimToNull($language) ?? self::DEFAULT_LANGUAGE);

        $controlledTopic = $this->gate->evaluate($lead);
        if ($controlledTopic !== null) {
            return $this->suppress($lead, $controlledTopic['reason'], $controlledTopic['detail'], $language);
        }

        $priceService = new VehiclePriceService($lead);

        $vehicleKey = $priceService->vehicleKey($vin);
        if ($vehicleKey === null) {
            return $this->noop(
                ['vehicle_in_scope' => false],
                guidance: 'No specific vehicle is in scope yet. Answer normally without stating any price; call this tool again with the VIN as soon as a unit is introduced.',
            );
        }

        $variant = $priceService->resolveVariant($vehicleKey);
        if ($variant === null) {
            return $this->suppress(
                $lead,
                PriceDisclosureReasonEnum::VEHICLE_UNRESOLVED,
                "Vehicle {$vehicleKey} could not be matched to a unit in inventory.",
                $language,
            );
        }

        $price = $priceService->priceFor($variant);
        if ($price === null) {
            return $this->suppress(
                $lead,
                PriceDisclosureReasonEnum::PRICE_MISSING,
                "No approved price is available for vehicle {$variant->sku}.",
                $language,
            );
        }

        $rendered = new RenderPriceDisclosureAction(
            $lead,
            $price,
            $disclosureChannel,
            $language,
        )->execute();

        $previous = $this->disclosures($lead)[$price->vehicleKey] ?? null;

        if ($previous === null) {
            $sentAt = $this->sentOutsideTheTool($lead, $rendered['message']);

            $this->recordDisclosure(
                $lead,
                $price->vehicleKey,
                $disclosureChannel,
                $rendered,
                $sentAt,
            );

            $previous = $sentAt !== null ? ['disclosed_at' => $sentAt->toIso8601String()] : null;
        }

        $alreadyDisclosed = $previous !== null;

        return $this->ok([
            'suppress' => false,
            'mode' => $alreadyDisclosed ? self::MODE_ALREADY_DISCLOSED : self::MODE_INSERT_BLOCK,
            'message' => $rendered['message'],
            'vehicle_key' => $price->vehicleKey,
            'channel' => $disclosureChannel->value,
            'language' => $language,
            'template' => $rendered['template'],
            'content_hash' => $rendered['content_hash'],
            'disclosed_at' => $previous['disclosed_at'] ?? null,
            'price' => [
                'ca_cars_total_price' => $price->caCarsTotalPrice,
                'pre_rebate_selling_price' => $price->preRebateSellingPrice,
                'ftc_actual_price' => $price->ftcActualPrice,
                'mandatory_dealer_fees' => array_column($price->mandatoryDealerFees, 'amount', 'type'),
                'mandatory_dealer_fees_total' => $price->mandatoryDealerFeesTotal(),
                'currency' => $price->currency,
                'source_system' => $price->sourceSystem,
                'source_version' => $price->sourceVersion,
            ],
        ], guidance: $alreadyDisclosed
            ? 'This vehicle\'s price was already given to this customer. Do not restate it; answer what they actually asked. Only if they ask for the price again, insert "message" exactly as written.'
            : 'Answer the customer, then insert "message" exactly as written right after you name the vehicle. No changes, additions, or other prices.');
    }

    private function suppress(
        Lead $lead,
        PriceDisclosureReasonEnum $reason,
        string $detail,
        string $language,
    ): array {
        $handoff = new SuppressAgentReplyAction($lead, $reason, $detail)->execute();

        $payload = [
            'suppress' => true,
            'reason_code' => $reason->value,
            'handoff' => $handoff,
        ];

        if ($reason === PriceDisclosureReasonEnum::OUT_THE_DOOR_UNSUPPORTED) {
            $payload['message'] = self::OUT_THE_DOOR_ACKNOWLEDGMENT[$language] ?? self::OUT_THE_DOOR_ACKNOWLEDGMENT[self::DEFAULT_LANGUAGE];

            return $this->denied(
                $detail,
                $payload,
                guidance: 'Send "message" exactly as written and nothing else. Do not estimate an out-the-door amount and do not say a calculator is missing. A human will follow up.',
            );
        }

        return $this->denied(
            $detail,
            $payload,
            guidance: 'Do not state, estimate, or promise any price. A human will follow up with the customer.',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function disclosures(Lead $lead): array
    {
        return (array) ($lead->get(PriceDisclosureConfigurationEnum::DISCLOSURES->value) ?? []);
    }

    /**
     * Recorded when the block is rendered, not when the message is delivered: the tool cannot see
     * the send. The spec accepts a duplicate disclosure but never a missing one, so the gap errs
     * toward repeating.
     *
     * @param array{template: string, template_id: int|null, message: string, content_hash: string} $rendered
     */
    private function recordDisclosure(
        Lead $lead,
        string $vehicleKey,
        PriceDisclosureChannelEnum $channel,
        array $rendered,
        ?Carbon $disclosedAt = null,
    ): void {
        $disclosures = $this->disclosures($lead);
        $disclosures[$vehicleKey] = [
            'channel' => $channel->value,
            'template' => $rendered['template'],
            'template_id' => $rendered['template_id'],
            'content_hash' => $rendered['content_hash'],
            'disclosed_at' => ($disclosedAt ?? Carbon::now())->toIso8601String(),
        ];

        $lead->set(PriceDisclosureConfigurationEnum::DISCLOSURES->value, $disclosures);
    }

    /**
     * The ledger only knows blocks this tool rendered. A block a salesperson, a template or a
     * campaign already sent on the lead's channel is just as disclosed, and repeating it reads as
     * a bot pasting the same paragraph twice. Matched verbatim (whitespace folded) because the
     * rendered block carries the stock number and every amount, so a hit is that vehicle's price.
     */
    private function sentOutsideTheTool(Lead $lead, string $block): ?Carbon
    {
        $needle = Str::squish($block);
        if ($needle === '') {
            return null;
        }

        foreach (MessagesRepository::getLatestMessagesByLead($lead, self::SENT_HISTORY_LIMIT) as $message) {
            $payload = $message->message;
            if (! is_array($payload) || empty($payload['from_me'])) {
                continue;
            }

            if (str_contains(Str::squish((string) ($payload['content'] ?? '')), $needle)) {
                return Carbon::parse($message->created_at);
            }
        }

        return null;
    }
}

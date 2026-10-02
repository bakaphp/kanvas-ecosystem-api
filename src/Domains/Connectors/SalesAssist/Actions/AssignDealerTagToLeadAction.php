<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * One company account can hold several rooftops, and a tag is the only way to tell which
 * rooftop a lead belongs to. The company config `lead-dealer-tags` lists them:
 *
 *   [{"tag": "Store North", "stock_prefixes": ["N"]}, ...]
 *
 * Each salesperson carries their rooftop's tag in the user setting `lead-dealer-tag` (a string, or a
 * list for someone who covers several). The lead owner's tag wins over the vehicle of interest's
 * stock number. An owner who is in no team falls back to the vehicle, so a lead handled by
 * someone outside the rooftops still lands somewhere.
 *
 * The activity runs on every lead update, so the trigger that assigned the tag is recorded on the
 * lead: an owner-assigned tag is final and later runs skip, while a vehicle-assigned tag can still
 * be replaced by an owner match (the owner supersedes the vehicle) or by a new vehicle's stock
 * number. Only one rooftop tag is kept.
 *
 * The owner and stock number each run saw are recorded too, because the activity runs queued after
 * the save: the model's own change tracking is gone by then, and many paths (agent tools, CRM
 * pulls) reassign owners or rewrite the vehicle without firing the update workflow at all. A tagged
 * lead whose owner and stock number both match the recorded ones skips before any lookup.
 */
class AssignDealerTagToLeadAction
{
    public const string TRIGGER_OWNER = 'lead_owner';
    public const string TRIGGER_VEHICLE = 'vehicle_of_interest';

    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    /**
     * @return array{tag: ?string, trigger: ?string, skipped: bool}
     */
    public function execute(): array
    {
        $previousTrigger = $this->lead->get(LeadCustomFieldEnum::DEALER_TAG_TRIGGER->value);

        if ($previousTrigger === self::TRIGGER_OWNER) {
            return ['tag' => null, 'trigger' => $previousTrigger, 'skipped' => true];
        }

        $ownerId = (int) $this->lead->leads_owner_id;
        $stockNumber = $this->stockNumber();
        $ownerChanged = $ownerId !== (int) $this->lead->get(LeadCustomFieldEnum::DEALER_TAG_OWNER_ID->value);
        $stockChanged = $stockNumber !== (string) $this->lead->get(LeadCustomFieldEnum::DEALER_TAG_STOCK_NUMBER->value);

        if ($previousTrigger !== null && ! $ownerChanged && ! $stockChanged) {
            return ['tag' => null, 'trigger' => $previousTrigger, 'skipped' => true];
        }

        $rules = self::rulesFor($this->lead->company);
        $stockMayDecide = $previousTrigger === null || $stockChanged;

        [$tag, $trigger] = $this->matchByOwner($rules)
            ?? ($stockMayDecide ? $this->matchByStockNumber($rules, $stockNumber) : null)
            ?? [null, null];

        if ($tag === null) {
            if ($previousTrigger !== null) {
                $this->rememberSeen($ownerId, $stockNumber);
            }

            return ['tag' => null, 'trigger' => $previousTrigger, 'skipped' => $previousTrigger !== null];
        }

        $otherTags = array_values(array_diff(array_column($rules, 'tag'), [$tag]));
        if ($otherTags !== []) {
            $this->lead->removeTags($otherTags);
        }

        $this->lead->addTag($tag);
        $this->lead->set(LeadCustomFieldEnum::DEALER_TAG_TRIGGER->value, $trigger);
        $this->rememberSeen($ownerId, $stockNumber);

        return ['tag' => $tag, 'trigger' => $trigger, 'skipped' => false];
    }

    /**
     * The rooftop tags this company configures — the ones only this action may set.
     *
     * @return list<string>
     */
    public static function dealerTags(Companies $company): array
    {
        return array_column(self::rulesFor($company), 'tag');
    }

    /**
     * @return array<int, array{tag: string, stock_prefixes: string[]}>
     */
    private static function rulesFor(Companies $company): array
    {
        $configured = Str::jsonToArray($company->get(ConfigurationEnum::LEAD_DEALER_TAGS->value));

        if (! is_array($configured)) {
            return [];
        }

        $rules = [];
        foreach ($configured as $rule) {
            $tag = is_array($rule) ? Str::trimToNull((string) ($rule['tag'] ?? '')) : null;
            if ($tag === null) {
                continue;
            }

            $rules[] = [
                'tag' => $tag,
                'stock_prefixes' => array_values(array_filter(
                    array_map(
                        fn ($prefix) => strtoupper(trim((string) $prefix)),
                        (array) ($rule['stock_prefixes'] ?? [])
                    ),
                    fn (string $prefix) => $prefix !== ''
                )),
            ];
        }

        return $rules;
    }

    private function matchByOwner(array $rules): ?array
    {
        $owner = $this->lead->owner;
        if (! $owner) {
            return null;
        }

        $ownerTags = (array) Str::jsonToArray($owner->get(ConfigurationEnum::USER_DEALER_TAG->value) ?? []);
        $ownerTags = array_map(fn ($tag) => Str::lowerTrim((string) $tag), $ownerTags);

        foreach ($rules as $rule) {
            if (in_array(Str::lowerTrim($rule['tag']), $ownerTags, true)) {
                return [$rule['tag'], self::TRIGGER_OWNER];
            }
        }

        return null;
    }

    private function matchByStockNumber(array $rules, string $stockNumber): ?array
    {
        if ($stockNumber === '') {
            return null;
        }

        foreach ($rules as $rule) {
            foreach ($rule['stock_prefixes'] as $prefix) {
                if (str_starts_with($stockNumber, $prefix)) {
                    return [$rule['tag'], self::TRIGGER_VEHICLE];
                }
            }
        }

        return null;
    }

    private function stockNumber(): string
    {
        $vehicle = (array) ($this->lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value) ?? []);

        return strtoupper(trim(
            (string) ($vehicle['stockNumber'] ?? $vehicle['stock_number'] ?? $vehicle['stock'] ?? '')
        ));
    }

    private function rememberSeen(int $ownerId, string $stockNumber): void
    {
        $this->lead->set(LeadCustomFieldEnum::DEALER_TAG_OWNER_ID->value, $ownerId);
        $this->lead->set(LeadCustomFieldEnum::DEALER_TAG_STOCK_NUMBER->value, $stockNumber);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Services;

use Kanvas\Connectors\SalesAssist\PriceDisclosure\Actions\SuppressAgentReplyAction;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\MessageIntentEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureReasonEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehiclePriceDisclosureTool;

/**
 * Deterministic last check between the model's draft and the send. The prompt tells the model to
 * route every price through the disclosure tool; this is what makes that true when it does not.
 */
class PriceDisclosureReplyGate
{
    private const string MONEY_PATTERN = '/(?:\$|USD)\s?\d[\d,]*(?:\.\d{1,2})?/i';

    public function __construct(
        private readonly MessageIntentDetector $intents = new MessageIntentDetector(),
    ) {
    }

    /**
     * @param list<string> $executedToolCalls "name:hash" entries from the turn that just ran
     * @param string|null $inboundText what the customer wrote, when the turn answers a message; null
     *                                 for an outreach, where nothing was asked yet
     *
     * @throws AgentReplySkippedException when the reply must not leave the platform
     */
    public function assertReplyAllowed(
        Lead $lead,
        string $response,
        array $executedToolCalls,
        ?string $inboundText = null,
    ): void {
        $suppressedReason = $lead->get(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value);

        if ($suppressedReason) {
            $this->clearSuppression($lead);

            throw $this->skipped($lead, (string) $suppressedReason);
        }

        if (! $this->requiresDisclosure($lead)) {
            return;
        }

        $toolRan = $this->disclosureToolRan($executedToolCalls);

        if ($inboundText !== null) {
            $this->assertInboundIntentIsCovered($lead, $inboundText, $toolRan);
        }

        if ($this->mentionsMoney($response) && ! $toolRan) {
            $this->suppress($lead, PriceDisclosureReasonEnum::PRICE_UNVERIFIED, 'The reply states a price that did not come from the disclosure tool.');
        }
    }

    /**
     * What the customer asked decides the obligation, not what the model chose to answer: a price
     * question met with "let me check and get back to you" is still a first written response.
     */
    private function assertInboundIntentIsCovered(Lead $lead, string $inboundText, bool $toolRan): void
    {
        $intents = $this->intents->detect($inboundText);

        if (in_array(MessageIntentEnum::PAYMENT, $intents, true) || in_array(MessageIntentEnum::COMPARISON, $intents, true)) {
            $this->suppress($lead, PriceDisclosureReasonEnum::PAYMENT_UNSUPPORTED, 'The customer asked about a monthly payment and no approved financing payload exists.');
        }

        if (in_array(MessageIntentEnum::ADD_ON, $intents, true)) {
            $this->suppress($lead, PriceDisclosureReasonEnum::ADD_ON_DISCLOSURE_MISSING, 'The customer asked about an add-on and no approved add-on disclosure exists.');
        }

        if (in_array(MessageIntentEnum::PRICE, $intents, true) && ! $toolRan && $this->intents->referencesVehicle($inboundText, $lead)) {
            $this->suppress($lead, PriceDisclosureReasonEnum::DISCLOSURE_MISSING, 'The customer asked for the price of a specific vehicle and the reply carries no approved disclosure.');
        }
    }

    private function suppress(Lead $lead, PriceDisclosureReasonEnum $reason, string $detail): never
    {
        new SuppressAgentReplyAction($lead, $reason, $detail)->execute();
        $this->clearSuppression($lead);

        throw $this->skipped($lead, $reason->value);
    }

    private function skipped(Lead $lead, string $reason): AgentReplySkippedException
    {
        return new AgentReplySkippedException(
            sprintf('Agent reply suppressed for lead %d: %s', $lead->getId(), $reason)
        );
    }

    private function requiresDisclosure(Lead $lead): bool
    {
        return (bool) $lead->company->get(PriceDisclosureConfigurationEnum::ENABLED->value);
    }

    private function mentionsMoney(string $response): bool
    {
        return preg_match(self::MONEY_PATTERN, $response) === 1;
    }

    /**
     * @param list<string> $executedToolCalls
     */
    private function disclosureToolRan(array $executedToolCalls): bool
    {
        foreach ($executedToolCalls as $call) {
            if (str_starts_with($call, VehiclePriceDisclosureTool::NAME . ':')) {
                return true;
            }
        }

        return false;
    }

    private function clearSuppression(Lead $lead): void
    {
        $lead->del(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value);
    }
}

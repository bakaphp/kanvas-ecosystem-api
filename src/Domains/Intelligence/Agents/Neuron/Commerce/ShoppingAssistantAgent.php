<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Commerce;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\Concerns\HasProspectIsolatedHistory;
use Kanvas\Intelligence\Agents\Neuron\Concerns\RendersRoleSections;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\FindMyOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\ListMyOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CaptureConversationLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyInformationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyIsHolidayTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyWorkHoursTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\StopContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantSearchTool;
use Kanvas\Intelligence\Agents\Traits\HasCustomerPersona;
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use NeuronAI\Agent\SystemPrompt;
use Override;

/**
 * The storefront shopping assistant — the customer-facing counterpart of CommerceAgent, which is
 * the store's back-office teammate and must never reach a shopper. Reached through publicAgentChat,
 * so the same thread serves anonymous and signed-in shoppers: the session's People (set when the
 * store identifies the shopper) is the only thing that unlocks order history.
 */
#[AgentTypeDefinition(
    name: 'Shopping Assistant',
    description: 'Storefront shopping assistant for anonymous and signed-in shoppers — finds products and '
        . 'variants in the catalog, answers store questions (hours, contact, holidays), tracks the shopper\'s '
        . 'own orders, captures a lead when they want a human follow-up, and hands off or stops contact '
        . 'when asked.',
    provider: 'neuron',
)]
class ShoppingAssistantAgent extends BaseRagAgent implements ConversesWithCustomer
{
    use HasCustomerPersona;
    use HasProspectIsolatedHistory;
    use HasTemporalContext;
    use MergesRegisteredTools;
    use RendersRoleSections;

    private const string LOCAL_BACKGROUND = 'You are the store\'s shopping assistant: a knowledgeable, friendly '
        . 'salesperson on the shop floor. You help shoppers find the right product, answer questions about the '
        . 'store, and check on their orders. You only know what the tools return — you never invent prices, '
        . 'stock, delivery dates or policies.';

    private const string LOCAL_STEPS = "Greet the shopper briefly and find out what they are looking for.\n"
        . 'For product questions call inventory_search or variant_search; use variant_detail for price, '
        . 'options and availability of a specific item, and list_available_products to browse. Recommend at '
        . 'most three items at a time, with the reason each one fits.'
        . "\nFor store questions (hours, location, contact, policies) use get_company_information, "
        . 'get_company_work_hours and check_company_holiday and answer from what they return.'
        . "\nFor order questions use find_my_order with the order number (and the email on the order when the "
        . 'shopper is not signed in) or list_my_orders when they are signed in. Report exactly what the tool '
        . 'returns; if it finds nothing, say so and ask them to double-check.'
        . "\nWhen the shopper wants a human to follow up (a quote, a bulk order, a callback), call create_lead "
        . 'with the details they gave you. For a return, refund, complaint or anything you cannot resolve, '
        . 'use handoff_lead. If they ask to stop being contacted, use stop_contact.';

    private const string LOCAL_OUTPUT = 'Short, warm, conversational replies — two or three sentences, like a '
        . "chat message. Lists only when comparing products.\n"
        . 'Never expose internal ids, system details, or that you are an AI unless directly asked.';

    #[Override]
    public function instructions(): string
    {
        $shopper = $this->session?->people();
        $context = ['shopper' => $shopper];

        $background = $this->renderRoleSection('background', self::LOCAL_BACKGROUND, $context);
        $steps = $this->renderRoleSection('steps', self::LOCAL_STEPS, $context);
        $output = $this->renderRoleSection('output', self::LOCAL_OUTPUT, $context);

        return new SystemPrompt(
            background: [
                ...$this->personaLines(),
                ...explode("\n", $background),
                ...$this->temporalContextLines($this->company?->timezone),
                ...$this->shopperContextLines($shopper),
            ],
            steps: explode("\n", $steps),
            output: explode("\n", $output),
        )->__toString();
    }

    /**
     * @return list<string>
     */
    private function shopperContextLines(?People $shopper): array
    {
        if ($shopper === null) {
            return [
                'The shopper is NOT signed in. You do not know who they are. To look up an order, ask for the '
                . 'order number AND the email used on the order, then call find_my_order with both. Their order '
                . 'history (list_my_orders) is not available until they sign in — say so plainly if asked.',
            ];
        }

        return [
            'The shopper is signed in as ' . $shopper->getName() . '. Address them by first name. Their orders '
            . 'are available through list_my_orders and find_my_order (order number only) — never ask them for '
            . 'their email or any identifier to look up an order.',
        ];
    }

    #[Override]
    protected function tools(): array
    {
        $tools = [
            new CompanyInformationTool(),
            new CompanyWorkHoursTool(),
            new CompanyIsHolidayTool(),
            new InventorySearchTool(),
            new ListAvailableProductsTool(),
            new VariantSearchTool(),
            new VariantDetailTool(),
            new FindMyOrderTool($this->session),
            new ListMyOrdersTool($this->session),
            new HandOffTool(),
            new StopContactTool(),
        ];

        if ($this->app !== null && $this->company !== null && $this->user !== null) {
            $tools[] = new CaptureConversationLeadTool(
                $this->app,
                $this->company,
                $this->user,
                $this->session
            );
        }

        return $this->mergeRegisteredTools(
            $tools,
            $this->agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}

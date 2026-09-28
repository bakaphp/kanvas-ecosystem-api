<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCompanyUserForTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Assigns a deal to a different owner (sales rep). The new owner is resolved by name/email but ONLY
 * among users of the current company — a deal can never be handed to a user outside the tenant.
 * Ambiguous matches return the candidates so the agent can disambiguate instead of guessing.
 * Company-wide write — an internal-teammate capability, NOT for the customer-facing prospect surface.
 */
#[AgentTool(name: 'Reassign Deal Owner', category: 'crm')]
class ReassignDealOwnerTool extends Tool
{
    use HasKanvasContext;
    use ResolvesCompanyUserForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'reassign_deal_owner',
            description: 'Assign a deal to another person (change the owner / assigned sales rep). Provide the deal_id '
                . 'and the new owner\'s name or email. Use search_deals first to find the deal_id and confirm the '
                . 'current owner. If the name matches several people the tool returns the candidates — ask which one, '
                . 'then call again with their email. Only users of this company can be assigned.',
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
                name: 'deal_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the deal to reassign (from search_deals).',
                required: true,
            ),
            new ToolProperty(
                name: 'new_owner',
                type: PropertyType::STRING,
                description: 'The new owner, by (partial) name or email. Email is safest when names collide.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $deal_id, string $new_owner): array
    {
        $new_owner = trim($new_owner);
        if ($new_owner === '') {
            return ['error' => 'Provide the new owner\'s name or email.'];
        }

        try {
            /** @var Deal $deal */
            $deal = Deal::getByIdFromCompanyApp($deal_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('Deal #%d not found in this company.', $deal_id)];
        }

        $candidates = $this->resolveCompanyUsers($new_owner);

        if ($candidates->isEmpty()) {
            return ['error' => sprintf('No user matching "%s" was found in this company.', $new_owner)];
        }

        if ($candidates->count() > 1) {
            return [
                'error' => sprintf('Multiple users match "%s". Ask which one, then call again with their email.', $new_owner),
                'candidates' => $candidates->map(fn (Users $u): array => [
                    'id' => $u->getId(),
                    'name' => trim($u->firstname . ' ' . $u->lastname),
                    'email' => $u->email,
                ])->all(),
            ];
        }

        /** @var Users $owner */
        $owner = $candidates->first();
        $previousOwner = $deal->owner;

        $deal->owner_id = $owner->getId();
        $deal->saveOrFail();

        return [
            'deal_id' => $deal->getId(),
            'title' => $deal->title,
            'previous_owner' => $previousOwner
                ? trim($previousOwner->firstname . ' ' . $previousOwner->lastname)
                : null,
            'new_owner' => [
                'id' => $owner->getId(),
                'name' => trim($owner->firstname . ' ' . $owner->lastname),
                'email' => $owner->email,
            ],
            'message' => 'Deal owner updated.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Reassigns a lead to a new owner (sales rep). The new owner is resolved by name/email but ONLY
 * among users of the current company — a lead can never be handed to a user outside the tenant.
 * Ambiguous matches return the candidates so the agent can disambiguate instead of guessing.
 * Company-wide write — an internal-teammate capability, NOT for the customer-facing prospect surface.
 */
#[AgentTool(name: 'Reassign Lead Owner', category: 'crm')]
class ReassignLeadOwnerTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'reassign_lead_owner',
            description: 'Change the owner (assigned sales rep) of a lead. Provide the lead_id and the new owner\'s '
                . 'name or email. Use search_leads first to find the lead_id and confirm the current owner. If the '
                . 'name matches several people the tool returns the candidates — ask which one, then call again with '
                . 'their email. Only users of this company can be assigned.',
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
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead to reassign (from search_leads or get_lead_ref).',
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
    public function __invoke(int $lead_id, string $new_owner): array
    {
        $new_owner = trim($new_owner);
        if ($new_owner === '') {
            return ['error' => 'Provide the new owner\'s name or email.'];
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('Lead #%d not found in this company.', $lead_id)];
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
        $previousOwner = $lead->owner;

        $lead->leads_owner_id = $owner->getId();
        $lead->saveOrFail();

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'previous_owner' => $previousOwner
                ? trim($previousOwner->firstname . ' ' . $previousOwner->lastname)
                : null,
            'new_owner' => [
                'id' => $owner->getId(),
                'name' => trim($owner->firstname . ' ' . $owner->lastname),
                'email' => $owner->email,
            ],
            'message' => 'Lead owner updated.',
        ];
    }

    /**
     * Users of the CURRENT company only (via users_associated_company) matching name or email.
     * CONCAT match lets "firstname lastname" resolve as a single query term.
     *
     * @return \Illuminate\Support\Collection<int, Users>
     */
    private function resolveCompanyUsers(string $term): \Illuminate\Support\Collection
    {
        $like = '%' . $term . '%';

        return Users::query()
            ->select('users.*')
            ->join('users_associated_company', 'users_associated_company.users_id', '=', 'users.id')
            ->where('users_associated_company.companies_id', $this->company->getId())
            ->where(
                fn ($q) => $q->where('users.firstname', 'like', $like)
                    ->orWhere('users.lastname', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhereRaw("CONCAT(users.firstname, ' ', users.lastname) like ?", [$like]),
            )
            ->distinct()
            ->limit(10)
            ->get();
    }
}

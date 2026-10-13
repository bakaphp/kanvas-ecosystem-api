<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Actions\AddRecipientsToCampaignAction;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

/**
 * Appends more people (no lead) to a campaign already created by create_lead_campaign — only
 * while that campaign is still scheduled for a future time. A campaign already sending or sent
 * has already handed its recipient list to the fan-out job; call create_lead_campaign again to
 * start a new one for those people instead.
 */
#[AgentTool(name: 'Add People To Campaign', category: 'crm')]
class AddPeopleToCampaignTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    protected string $name = 'add_people_to_campaign';

    protected ?string $description = 'Add more people to an existing EMAIL campaign created by create_lead_campaign, '
        . 'identified by its campaign_id — only works while that campaign is still scheduled for a future time. '
        . 'A campaign that is already sending or already sent cannot be appended to; call create_lead_campaign again '
        . 'to start a new one for those people instead. Re-verifies eligibility the same way create_lead_campaign '
        . 'does (opted-out / do-not-contact / undeliverable / already-in-this-campaign are excluded). Admin-only.';

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'campaign_id',
                type: PropertyType::INTEGER,
                description: 'The id of the existing, still-scheduled campaign to add people to.',
                required: true,
            ),
            new ArrayProperty(
                name: 'people_ids',
                description: 'Additional people ids to add. At least one is required.',
                required: true,
                items: new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'A person id.'),
            ),
        ];
    }

    /**
     * @param  list<int>  $people_ids
     *
     * @return array<string, mixed>
     */
    public function __invoke(int $campaign_id, array $people_ids): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error', 'message' => $denied['message']];
        }

        $campaign = Campaign::query()->where('id', $campaign_id)->fromApp($this->app)->fromCompany($this->company)->first();
        if ($campaign === null) {
            return ['status' => 'error', 'message' => "No campaign found with id {$campaign_id} for this company."];
        }

        if ($campaign->channel !== 'email') {
            return ['status' => 'error', 'message' => 'Only email campaigns support people-only recipients in this version.'];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $people_ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return ['status' => 'error', 'message' => 'Provide at least one valid person id.'];
        }

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->get();

        $resolved = new BatchRecipientResolverService()->resolvePeople($people, 'email');
        if ($resolved['eligible'] === []) {
            return [
                'status' => 'error',
                'message' => 'None of the given people are eligible to add to this campaign.',
                'excluded' => $resolved['excluded'],
            ];
        }

        try {
            $result = new AddRecipientsToCampaignAction($campaign)->execute($resolved['eligible']);
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'Could not add people to the campaign: ' . $e->getMessage()];
        }

        return [
            'status' => 'success',
            'campaign_id' => $campaign->getId(),
            'added' => $result['added'],
            'already_in_campaign' => $result['already_in_campaign'],
            'excluded' => $resolved['excluded'],
            'note' => $this->buildNote($result['added'], $result['already_in_campaign'], $resolved['excluded']),
        ];
    }

    /**
     * @param  list<int>  $alreadyInCampaign
     * @param  array<int, array<string, mixed>>  $excluded
     */
    private function buildNote(int $added, array $alreadyInCampaign, array $excluded): string
    {
        $note = "{$added} new recipient(s) added to the campaign.";

        if ($alreadyInCampaign !== []) {
            $note .= ' ' . count($alreadyInCampaign) . ' of the given ids were already in this campaign and were skipped (not duplicated).';
        }

        if ($excluded !== []) {
            $note .= ' ' . count($excluded) . ' of the given people were excluded and will NOT receive this email — '
                . 'always tell the user which ones and why (see excluded[].compliance_status; no_contact_info means '
                . 'that person has no email on file).';
        }

        return $note;
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Actions\CreateLeadSocialChannelsAction;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class ContactObserver
{
    public function creating(Contact $contact): void
    {
        $this->cleanPhoneNumber($contact);
    }

    public function updating(Contact $contact): void
    {
        $this->cleanPhoneNumber($contact);
    }

    public function created(Contact $contact): void
    {
        $this->runWorkflow($contact);
        $this->createSocialChannels($contact);
    }

    public function updated(Contact $contact): void
    {
        $this->runWorkflow($contact);

        if ($contact->wasChanged(['value', 'contacts_types_id'])) {
            $this->createSocialChannels($contact);
        }
    }

    private function cleanPhoneNumber(Contact $contact): void
    {
        if (! empty($contact->value) && Contact::isPhoneType((int) $contact->contacts_types_id)) {
            $contact->value = Contact::cleanPhone($contact->value);
        }
    }

    private function runWorkflow(Contact $contact): void
    {
        // `people` resolves to null when the person is soft-deleted, which happens on import of
        // historical records that were deleted at the source. A contact with no reachable person
        // has no workflow context, so there is nothing to fire.
        if ($contact->people?->company?->isAIEnabled() !== true) {
            return;
        }

        $contact->fireWorkflow(
            WorkflowEnum::CONTACT_SAVED->value,
            true,
            [
               'company' => $contact->people->company,
               'app' => $contact->people->app,
            ]
        );
    }

    /**
     * A contact added after the lead was pulled has no channel to talk on until the next pull, so
     * open them here on the person's active lead, or on their latest one when none is active.
     */
    private function createSocialChannels(Contact $contact): void
    {
        $people = $contact->people;

        if ($people?->company === null) {
            return;
        }

        // The app turns it on for everyone; a company can only opt out.
        $setting = ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value;

        $companyValue = $people->company->get($setting);
        $companyOptedOut = $companyValue !== null && ! filter_var($companyValue, FILTER_VALIDATE_BOOL);

        if (! $people->app->getBool($setting) || $companyOptedOut) {
            return;
        }

        // Never block the contact save on channel creation.
        try {
            // Every channel needs an agent for its session, so without one there is nothing to open.
            $defaultAgentId = $this->resolveDefaultAgentId($people);

            if ($defaultAgentId === null) {
                return;
            }

            $lead = LeadsRepository::getPeopleActiveLead($people) ?? LeadsRepository::getPeopleLastLead($people);

            if ($lead === null) {
                return;
            }

            $agentId = $this->resolveSessionAgentId($lead) ?? $defaultAgentId;

            new CreateLeadSocialChannelsAction(
                $lead,
                $people->app,
                [],
                $agentId,
            )->execute();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The same lookup PullLeadActivity opens a lead's channels with.
     */
    private function resolveDefaultAgentId(People $people): ?int
    {
        $agentName = $people->company->get(SalesAssistConfigurationEnum::AGENT_NAME->value)
            ?? SalesAssistConfigurationEnum::DEFAULT_AGENT_NAME;

        return Agent::where('name', $agentName)
            ->fromApp($people->app)
            ->fromCompany($people->company)
            ->notDeleted()
            ->value('id');
    }

    /**
     * Sessions are keyed by agent, so opening the channels with a different agent than the one the
     * lead already talks to would add a second session to each of them.
     */
    private function resolveSessionAgentId(Lead $lead): ?int
    {
        $agentId = $lead->aiSession()
            ->where('canal_id', 'not like', SessionChannelService::createCanalId('ai-assist', '') . '%')
            ->latest('id')
            ->value('agents_id');

        return $agentId !== null && Agent::where('id', $agentId)->notDeleted()->exists()
            ? (int) $agentId
            : null;
    }
}

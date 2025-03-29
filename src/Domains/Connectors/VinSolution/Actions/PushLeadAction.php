<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class PushLeadAction
{
    protected ClientCredential $vinCredential;
    protected ?Lead $vinLead = null;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->vinCredential = ClientCredential::get(
            $this->lead->company,
            $this->lead->user,
            $this->lead->app
        );
    }

    /**
     * Execute the action to push the person to VinSolutions.
     */
    public function execute(): Lead
    {
        $leadId = CustomFieldEnum::LEADS->value;

        $pushContact = new PushPeopleAction(
            $this->lead->people
        );
        $contact = $pushContact->execute();
        // Check if lead exists in VinSolutions
        $vinLeadId = $this->lead->get($leadId);

        if (! $vinLeadId) {
            $leadData = [
                'leadSource' => $this->lead->source && ! empty($this->lead->source->description)
                    ? trim($this->lead->source->description)
                    : 55694, // default source ID
                'leadType' => strtoupper($this->lead->type->name),
                'contact' => $contact->id,
                'isHot' => false,
            ];

            $vinLead = Lead::create(
                $this->vinCredential->dealer,
                $this->vinCredential->user,
                $leadData
            );

            $this->lead->set($leadId, $vinLead->id);
        } else {
            $vinLead = Lead::getById(
                $this->vinCredential->dealer,
                $this->vinCredential->user,
                $vinLeadId
            );

            $vinLead->leadSource = $this->lead->source && ! empty($this->lead->source->description)
                ? trim($this->lead->source->description)
                : 55694; // default source ID
            //$vinLead->isHot = $this->lead->isHot ? 1 : 0;

            $vinLead->update(
                $this->vinCredential->dealer,
                $this->vinCredential->user,
            );
        }

        $this->vinLead = $vinLead;

        $this->addReferralNotes();
        $this->updateShowRoom();

        return $vinLead;
    }

    /**
     * Update lead's showroom status.
     */
    public function updateShowRoom(): bool
    {
        if (! $this->vinLead) {
            return false;
        }

        if (! $this->lead->get('is_chrono_running') && ! $this->lead->get('currentUser')) {
            return false;
        }

        $user = $this->lead->get('currentUser');
        if (! $user) {
            return false;
        }

        $this->vinLead->startShowRoom($this->vinCredential->dealer, $this->vinCredential->user);
        $this->vinLead->addNotes(
            $this->vinCredential->dealer,
            $this->vinCredential->user,
            $user->firstname . ' ' . $user->lastname . ' started a Showroom visit.'
        );

        $this->lead->del('currentUser');

        return true;
    }

    /**
     * Add referral notes to lead.
     */
    public function addReferralNotes(): bool
    {
        if (! $this->vinLead) {
            return false;
        }
        $referral = $this->lead->get('referral');

        $this->vinLead->addNotes(
            $this->vinCredential->dealer,
            $this->vinCredential->user,
            $referral
        );

        $this->lead->del('referral');

        return true;
    }
}

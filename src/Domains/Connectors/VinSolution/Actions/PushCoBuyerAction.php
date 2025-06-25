<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\DataTransferObject\CreditApp;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Connectors\VinSolution\Services\ContactService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PushCoBuyerAction
{
    public function __construct(
        protected ModelsLead $lead,
        protected Message $message,
    ) {
    }

    public function execute(): ?People
    {
        $vinCompany = Dealer::getById($this->lead->company->get(ConfigurationEnum::COMPANY->value), $this->lead->app);

        $vinUserId = $this->lead->user->get(ConfigurationEnum::getUserKey($this->lead->company, $this->lead->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $vinCredential = ClientCredential::get(
            $this->lead->company,
            $this->lead->user,
            $this->lead->app
        );

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->app,
        );
        $vinLead = Lead::getById(
            $vinCompany,
            $user,
            $this->lead->get(CustomFieldEnum::LEADS->value)
        );

        $message = $this->message->getMessage();
        $formData = $message['data']['form'];
        $mobilePhone = $formData['personal']['mobile_number'] ?? '';
        $name = $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'] ?? '';
        $email = $formData['personal']['email'];

        if (! empty($mobilePhone)) {
            $people = People::findByPhoneOrCreate(
                $mobilePhone,
                $this->lead->company,
                $this->lead->user,
                $name,
                $this->lead->app
            );
        } elseif (! empty($email)) {
            $people = People::findByEmailOrCreate(
                $formData['personal']['email'],
                $this->lead->company,
                $this->lead->user,
                $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'],
                $this->lead->app,
            );
        }

        if (! $people) {
            return null;
        }

        $people->name = $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'] ?? '';
        $people->firstname = $formData['personal']['first_name'] ?? '';
        $people->middlename = $formData['personal']['middle_name'] ?? '';
        $people->lastname = $formData['personal']['last_name'] ?? '';
        $people->disableWorkflows();
        $people->saveOrFail();
        $people->enableWorkflows();
        //add address

        if ($people->getId() !== $this->lead->people->getId()) {
            $vinContactService = new ContactService(
                $vinCredential,
            );

            $people->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true,
                [
                    'app' => $this->lead->app,
                ]
            );
            $this->lead->addCoBuyerParticipant($people);

            if ($contactId = $people->get(ConfigurationEnum::CONTACT->value)) {
                //update cosigner with all info
                if ($contact = $vinContactService->getContactByPeople($people)) {
                    $contactInfo = CreditApp::fromMessage($this->message);
                    $people->set(PeopleCustomFieldEnum::CREDIT_APP->value, $contactInfo);

                    $contact->addresses = $contactInfo->address;
                    $contact->personalInformation = $contactInfo->personalInformation;
                    $contact->licenseData = $contactInfo->licenseData;
                    $contact->update($vinCompany, $user);
                }

                $vinLead->coBuyerContact = $contactId;
                $vinLead->update(
                    $vinCompany,
                    $user,
                );

                //$lead->addCoBuyer($people);

                return $people;
            }
        }

        return null;
    }
}

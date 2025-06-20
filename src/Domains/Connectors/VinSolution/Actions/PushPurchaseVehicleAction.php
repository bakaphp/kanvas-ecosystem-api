<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\DataTransferObject\TradeIn as DataTransferObjectTradeIn;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Connectors\VinSolution\Services\ContactService;
use Kanvas\Connectors\VinSolution\Vehicles\TradeIn;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class PushPurchaseVehicleAction
{
    public function __construct(
        protected ModelsLead $lead,
        protected Message $message,
    ) {
    }

    public function execute(): array
    {
        $vinCompany = Dealer::getById($this->lead->company->get(ConfigurationEnum::COMPANY->value), $this->lead->app);

        $vinUserId = $this->lead->user->get(ConfigurationEnum::getUserKey($this->lead->company, $this->lead->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->app,
        );

        $vinCredential = ClientCredential::get(
            $this->lead->company,
            $this->lead->user,
            $this->lead->app
        );

        $vinLead = Lead::getById(
            $vinCompany,
            $user,
            $this->lead->get(CustomFieldEnum::LEADS->value)
        );

        $message = $this->message->getMessage();
        $people = $this->lead->people;
        $vinContact = new ContactService(
            $vinCredential,
        );

        $formData = $message['data']['documentForms'][6];
        $formDataFirst = $message['data']['documentForms'][0];
        $formDataFifth = $message['data']['documentForms'][5];

        $result = [];

        if (isset($formData['form']['contact.license']) && isset($formData['form']['contact.address.state'])) {
            $result['licenseData']['LicenseID'] = $formData['form']['contact.license'];
            //$result['licenseData']['Country'] = 'US';
            // $result['licenseData']['State'] = $formData['form']['contact.address.state'];
        }

        $contact = $vinContact->getContactByPeople($people);

        if ($result['licenseData']) {
            $contact->licenseData = $result['licenseData'];

            $result[] = $contact->update($vinCompany, $user);
        }

        try {
            $vehicle = TradeIn::getByLeadId(
                $vinCompany,
                $user,
                $this->lead->get(ConfigurationEnum::LEADS->value)
            )->items;
        } catch (Throwable $e) {
            $vehicle = [];
        }

        if (count($vehicle) < 3) {
            $newTradeData = [
                'data' => [
                    'form' => [
                        'vin' => $formData['form']['lead.trade-in.vin'],
                        'year' => $formData['form']['lead.trade-in.year'],
                        'make' => $formData['form']['lead.trade-in.make'],
                        'model' => $formData['form']['lead.trade-in.model'],
                        'ext_color' => $formDataFirst['form']['lead.trade-in.color'],
                        'int_color' => $formDataFirst['form']['lead.trade-in.color'],
                        'body_style' => $formData['form']['lead.trade-in.body_type'],
                        'engine' => $formData['form']['engine'],
                        'payoff_amount' => $formDataFifth['form']['lead.trade-in.payoff'],
                        'mileage' => $formDataFirst['form']['lead.trade-in.odometer'],
                        'value' => $formData['form']['lead.trade-in.value'],
                    ],
                ],
            ];

            //overwrite the message with the new trade data
            $this->message->message = $newTradeData;

            $result[] = $vinLead->addTradeIn(
                $vinCompany,
                $user,
                DataTransferObjectTradeIn::from($this->message, $this->lead)->toVinSolutionArray()
            );
        }

        return $result;
    }
}

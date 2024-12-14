<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Actions;

use Kanvas\Connectors\OfferLogix\Client;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Connectors\OfferLogix\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Locations\Models\States;

class SoftPullAction
{
    private const DEFAULT_PHONE = '8090000000';
    private const DEFAULT_STATE = 'GA';
    private const DEFAULT_SOURCE_ID = 19069;
    private const SXML_VALUE = '3';
    private const MAX_PHONE_LENGTH = 10;

    public function __construct(
        protected Lead $lead,
        protected People $people
    ) {
    }

    public function execute(SoftPull $softPull): ?string
    {
        $offerLogixClient = new Client($this->lead->app, $this->lead->company);
        $sourceCodeCompany = $this->lead->company->get(ConfigurationEnum::COMPANY_SOURCE_ID) ?? self::DEFAULT_SOURCE_ID;
        $sourceId = $sourceCodeCompany; //@todo check if this is configurable
        $sxml = self::SXML_VALUE;

        //$address = $this->people->address->count() ? $this->people->address->getFirst() : null;
        $phone = $this->people->getPhones();
        $phoneWeight = $this->people->contacts()
            ->where('contacts_types_id', ContactTypeEnum::PHONE->value)
            ->orderBy('weight', 'DESC')->groupBy('value')
            ->get();

        $cellphones = $this->people->getCellPhones();

        $state = States::where('name', $softPull->state)->first();

        if ($phoneWeight->count()) {
            $phone = $phoneWeight->first()->value;
        } elseif ($cellphones->count()) {
            $phone = $cellphones->first()->value;
        } elseif ($phone->count()) {
            $phone = $phone->first()->value;
        } else {
            $phone = self::DEFAULT_PHONE;
        }

        $phone = preg_replace('/\D+/', '', $phone);

        if (strlen($phone) > self::MAX_PHONE_LENGTH) {
            $phone = substr($phone, -self::MAX_PHONE_LENGTH);
        }

        $requestData = [
            'Source' => $sourceId,
            'sxml' => $sxml,
            'responseType' => 'json',
            'ConsumerFirstName' => $this->people->firstname,
            'ConsumerLastName' => $this->people->lastname,
            //'ConsumerStreetName' => $address->address ?? null,
            'ConsumerDOB' => $people->dob ?? null,
            'ConsumerCity' => $softPull->city, //$address->city ?? null,
            'ConsumerState' => $state ? $state->code : self::DEFAULT_STATE, // $address->state ?a? null,
            'ConsumerSSN' => $softPull->last_4_digits_of_ssn,
            //'ConsumerZip' => $address->zip ?? null,
            'ConsumerCellPhone' => $phone,
        ];

        $response = $offerLogixClient->post(
            '/quote/SoftpullQuote.php',
            $requestData
        );

        $this->people->set(CustomFieldEnum::SOFT_PULLED->value . '_REQUEST', $requestData);

        if (! isset($response['ContactCreditURL']) || empty($response['ContactCreditURL'])) {
            return null;
        }

        $this->people->set(CustomFieldEnum::SOFT_PULLED->value, $response);

        return $response['ContactCreditURL'];
    }
}

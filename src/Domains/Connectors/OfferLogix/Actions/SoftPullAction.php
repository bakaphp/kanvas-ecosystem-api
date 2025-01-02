<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Actions;

use Kanvas\Connectors\OfferLogix\Client;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Connectors\OfferLogix\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class SoftPullAction
{
    private const DEFAULT_STATE = 'GA';
    private const SXML_VALUE = '3';

    public function __construct(
        protected Lead $lead,
        protected People $people
    ) {
    }

    public function execute(SoftPull $softPull): ?string
    {
        $offerLogixClient = new Client($this->lead->app, $this->lead->company);
        $sourceCodeCompany = $this->lead->company->get(ConfigurationEnum::COMPANY_SOURCE_ID->value);

        if (! $sourceCodeCompany) {
            throw new ValidationException('OfferLogix Company source code not found');
        }

        $sourceId = $sourceCodeCompany; //@todo check if this is configurable
        $sxml = self::SXML_VALUE;
        $state = States::where('name', $softPull->state)->first();

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
            'ConsumerCellPhone' => $softPull->mobile,
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

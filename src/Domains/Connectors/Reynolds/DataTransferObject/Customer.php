<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\Data;

class Customer extends Data
{
    use ScalarCoercionTrait;

    public function __construct(
        public readonly bool $isBusiness,
        public readonly ?string $nameRecId,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $middleName,
        public readonly ?string $businessName,
        public readonly array $address,
        public readonly array $phones,
        public readonly ?string $email,
        public readonly array $consent,
        public readonly ?string $language = null,
        public readonly ?string $preferredContact = null,
        public readonly ?string $sendTextsTo = null,
    ) {
    }

    public static function fromPeople(People $people): self
    {
        $isBusiness = (string) $people->get(CustomFieldEnum::CONTACT_TYPE->value) === 'B';
        $nameRecId = $people->get(CustomFieldEnum::NAME_REC_ID->value);

        return new self(
            isBusiness: $isBusiness,
            nameRecId: $nameRecId !== null ? (string) $nameRecId : null,
            firstName: $people->firstname,
            lastName: $people->lastname,
            middleName: $people->middlename,
            businessName: $isBusiness ? ($people->organization ?? $people->firstname) : null,
            address: self::buildAddress($people),
            phones: self::buildPhones($people),
            email: self::buildEmail($people),
            consent: self::buildConsent($people),
            language: $people->get(CustomFieldEnum::LANGUAGE->value),
            preferredContact: $people->get(CustomFieldEnum::PREFERRED_CONTACT->value),
            sendTextsTo: $people->get(CustomFieldEnum::SEND_TEXTS_TO->value),
        );
    }

    /**
     * Builds the IndividualCustomer XML node payload.
     */
    public function toIndividualCustomer(): array
    {
        return array_filter([
            'IBFlag' => 'I',
            'LastName' => $this->lastName,
            'FirstName' => $this->firstName,
            'MidName' => $this->middleName,
            'Language' => $this->language,
            'PrefContact' => $this->preferredContact,
            'SendTextsTo' => $this->sendTextsTo,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Builds the BusinessCustomer XML node payload.
     */
    public function toBusinessCustomer(): array
    {
        return array_filter([
            'IBFlag' => 'B',
            'BusinessName' => $this->businessName,
            'ContactFirstName' => $this->firstName,
            'ContactLastName' => $this->lastName,
            'Language' => $this->language,
            'PrefContact' => $this->preferredContact,
            'SendTextsTo' => $this->sendTextsTo,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function toAddress(): array
    {
        return array_filter($this->address, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Returns the PhoneNumbers payload in Reynolds shape: { Phone: [ {Type,Num}, ... ] }.
     */
    public function toPhoneNumbers(): array
    {
        if (empty($this->phones)) {
            return [];
        }

        return ['Phone' => $this->phones];
    }

    public function toEmail(): array
    {
        return $this->email !== null ? ['MailTo' => $this->email] : [];
    }

    public function toConsent(): array
    {
        return array_filter($this->consent, fn ($v) => $v !== null && $v !== '');
    }

    private static function buildAddress(People $people): array
    {
        $address = $people->getDefaultAddress();

        if ($address === null) {
            return [];
        }

        return [
            'Addr1' => $address->address ?? null,
            'Addr2' => $address->address_2 ?? null,
            'City' => $address->city ?? null,
            'State' => $address->state ?? null,
            'Zip' => $address->zip ?? null,
            'County' => $address->county ?? null,
            'Country' => $address->country?->code ?? 'US',
        ];
    }

    private static function buildPhones(People $people): array
    {
        $phones = [];

        foreach ($people->getPhones() as $phone) {
            $phones[] = [
                'Type' => 'H',
                'Num' => preg_replace('/\D+/', '', (string) $phone->value),
            ];
        }

        foreach ($people->getCellPhones() as $phone) {
            $phones[] = [
                'Type' => 'C',
                'Num' => preg_replace('/\D+/', '', (string) $phone->value),
            ];
        }

        return array_filter($phones, fn ($p) => ! empty($p['Num']));
    }

    private static function buildEmail(People $people): ?string
    {
        $emails = $people->getEmails();
        $first = $emails->first();

        if ($first === null) {
            return null;
        }

        return filter_var($first->value, FILTER_VALIDATE_EMAIL) ? (string) $first->value : null;
    }

    private static function buildConsent(People $people): array
    {
        return [
            'Email' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_EMAIL->value)),
            'Text' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_TEXT->value)),
            'Phone' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_PHONE->value)),
            'Mail' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_MAIL->value)),
            'OptOut' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_OPT_OUT->value)) ?? 'N',
            'OptOutUse' => self::ynOrNull($people->get(CustomFieldEnum::CONSENT_OPT_OUT_USE->value)),
        ];
    }
}

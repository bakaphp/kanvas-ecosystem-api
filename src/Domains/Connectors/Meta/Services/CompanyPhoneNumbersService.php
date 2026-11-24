<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Meta\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Meta\Client;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
use Kanvas\Connectors\Meta\Handlers\MetaWhatsAppHandler;

/**
 * Every number of the WhatsApp Business Account the company connected, read from Meta, with
 * whether the company communicates through it. What a number picker offers: connecting one is
 * still the integration's job, because a WABA can hold numbers the company runs somewhere else.
 */
final class CompanyPhoneNumbersService
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company
    ) {
    }

    /**
     * @return list<array{
     *     phone_number_id: string,
     *     phone_number: string,
     *     display_phone_number: string,
     *     verified_name: string|null,
     *     quality_rating: string|null,
     *     is_connected: bool
     * }>
     */
    public function list(): array
    {
        $wabaId = (string) $this->company->get(ConfigurationEnum::WABA_ID->value);

        // Not connected is an answer, not a fault: there are no numbers to list.
        if ($wabaId === '' || empty($this->company->get(ConfigurationEnum::ACCESS_TOKEN->value))) {
            return [];
        }

        $connected = Connection::query()
            ->fromApp($this->app->getId())
            ->fromCompany($this->company->getId())
            ->live()
            ->where('provider', MetaWhatsAppHandler::slug())
            ->pluck('account_key')
            ->flip();
        $numbers = [];

        foreach (Client::forCompany($this->app, $this->company)->phoneNumbers($wabaId) as $number) {
            $displayPhoneNumber = Str::trimToNull($number['display_phone_number'] ?? null);

            if ($displayPhoneNumber === null) {
                continue;
            }

            $phoneNumber = Str::toE164($displayPhoneNumber);
            $numbers[] = [
                'phone_number_id' => (string) $number['id'],
                'phone_number' => $phoneNumber,
                'display_phone_number' => $displayPhoneNumber,
                'verified_name' => $number['verified_name'] ?? null,
                'quality_rating' => $number['quality_rating'] ?? null,
                'is_connected' => $connected->has($phoneNumber),
            ];
        }

        return $numbers;
    }
}

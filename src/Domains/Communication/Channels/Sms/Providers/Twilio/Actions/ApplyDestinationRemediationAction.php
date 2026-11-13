<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;

/**
 * What a carrier failure says about the number itself. `21610` is the person's own STOP, so the
 * number is opted out; `30003`, `30005` and `30006` mean the number cannot be reached at all, so the
 * matching contacts are marked invalid. Nothing is retried: see this provider's CLAUDE.md.
 */
final class ApplyDestinationRemediationAction
{
    private const array REMEDIATED_CODES = ['21610', '30003', '30005', '30006'];

    private const string OPTED_OUT_CODE = '21610';

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly string $destination,
        private readonly string $errorCode
    ) {
    }

    public function execute(): void
    {
        $destination = trim($this->destination);

        if ($destination === '' || ! in_array($this->errorCode, self::REMEDIATED_CODES, true)) {
            return;
        }

        $normalizedDestination = Contact::normalizeValue($destination, ContactTypeEnum::CELLPHONE->value);

        $people = PeoplesRepository::getByPhoneNumber(
            app: $this->app,
            company: $this->company,
            phoneNumbers: [$destination, Contact::cleanPhone($destination)],
        )->get();

        foreach ($people as $person) {
            if ($this->errorCode === self::OPTED_OUT_CODE) {
                $person->setPhoneOptOut($destination);

                continue;
            }

            $person->contacts()
                ->whereIn('contacts_types_id', Contact::PHONE_TYPES)
                ->get()
                ->filter(
                    fn (Contact $contact): bool => Contact::normalizeValue(
                        $contact->value,
                        $contact->contacts_types_id,
                    ) === $normalizedDestination,
                )
                ->each(fn (Contact $contact): bool => $contact->markInvalid());
        }
    }
}

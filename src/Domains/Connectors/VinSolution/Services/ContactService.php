<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Services;

use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class ContactService
{
    public function __construct(
        protected ClientCredential $vinCredential,
    ) {
    }

    public function getContactByPeople(People $people): Contact
    {
        try {
            $contact = Contact::getById(
                $this->vinCredential->dealer,
                $this->vinCredential->user,
                $people->get(CustomFieldEnum::CONTACT->value)
            );
        } catch (Throwable $e) {
            // It means we have a contact moved or deleted, need to find it and update it
            $email = $people->getEmails()->first();
            $phone = $people->getPhones()->first();
            $cellphone = $people->getCellPhones()->first();

            if ($email) {
                $contacts = Contact::getAll(
                    $this->vinCredential->dealer,
                    $this->vinCredential->user,
                    '',
                    [
                        'email' => $email->value,
                    ]
                );

                if (!empty($contacts)) {
                    if (isset($contacts[0])) {
                        $people->set(CustomFieldEnum::CONTACT->value, $contacts[0]['ContactId']);
                        $contact = Contact::getById(
                            $this->vinCredential->dealer,
                            $this->vinCredential->user,
                            $contacts[0]['ContactId']
                        );
                    }
                }
            }

            if (!isset($contact) && ($phone || $cellphone)) {
                $contacts = Contact::getAll(
                    $this->vinCredential->dealer,
                    $this->vinCredential->user,
                    '',
                    [
                        'phone' => $phone ? $phone->value : $cellphone->value,
                    ]
                );

                if (!empty($contacts)) {
                    if (isset($contacts[0])) {
                        $people->set(CustomFieldEnum::CONTACT->value, $contacts[0]['ContactId']);
                        $contact = Contact::getById(
                            $this->vinCredential->dealer,
                            $this->vinCredential->user,
                            $contacts[0]['ContactId']
                        );
                    }
                }
            }

            // If we still don't have a contact, throw the original exception to maintain behavior
            if (!isset($contact)) {
                throw $e;
            }
        }

        return $contact;
    }
}

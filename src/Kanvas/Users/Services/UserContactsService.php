<?php

declare(strict_types=1);

namespace Kanvas\Users\Services;

use Generator;

class UserContactsService
{
    public static function extractEmailsFromContactsList(array $contactsData): Generator
    {
        $seenEmails = [];

        foreach ($contactsData as $contact) {
            foreach (array_column($contact['emails'], 'email') as $email) {
                if (!isset($seenEmails[$email])) {
                    $seenEmails[$email] = true;
                    yield $email;
                }
            }
        }
    }
}

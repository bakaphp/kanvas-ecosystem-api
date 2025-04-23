<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Services\UserContactsService;

class UserContactsManagementMutation
{
    public function checkUsersContactsMatch(mixed $root, array $request): array
    {
        $authUser = auth()->user();
        $app = app(Apps::class);
        $contacts = $request['contacts'];
        $contactsEmails = [];
        foreach (UserContactsService::extractEmailsFromContactsList($contacts) as $email) {
            $contactsEmails[] = $email;
        }

        $appUsers = UsersAssociatedApps::fromApp($app)
            ->notDeleted()
            ->whereNotNull('email')
            ->whereNotIn('email', [$authUser->email])
            ->with('user')
            ->lazy();

        $contactsEmails = array_flip($contactsEmails);
        $matchingContacts = [];

        // Efficient lookup using isset()
        foreach ($appUsers as $appUser) {
            if (isset($contactsEmails[$appUser->email])) {
                $matchingContacts[] = $appUser->user;
                unset($contactsEmails[$appUser->email]);
            }
        }

        return [
            'matched_contacts'   => $matchingContacts,
            'unmatched_contacts' => array_flip($contactsEmails),
        ];
    }
}

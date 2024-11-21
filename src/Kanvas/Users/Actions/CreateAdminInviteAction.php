<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Kanvas\Users\DataTransferObject\AdminInvite as AdminInviteDto;
use Kanvas\Users\Models\AdminInvite;
use Kanvas\Users\Models\Users;

class CreateAdminInviteAction
{
    public function __construct(
        public AdminInviteDto $inviteDto,
        public Users $user
    ) {
    }

    /**
     * execute.
     */
    public function execute(): AdminInvite
    {
        // Still figuring out this validation.
        // if($this->user->isAdmin()) {
        //     throw new ValidationException('You must be owner for invite admins'); ///Temporal
        // };

        $invite = new AdminInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $this->user->getId(),
            'apps_id' => $this->inviteDto->app->getId(),
            'email' => $this->inviteDto->email,
            'firstname' => $this->inviteDto->firstname,
            'lastname' => $this->inviteDto->lastname,
            'description' => $this->inviteDto->description,
        ]);

        $invite->saveOrFail();

        if (count($this->inviteDto->customFields)) {
            $invite->setCustomFields($this->inviteDto->customFields);
            $invite->saveCustomFields();
        }

        //@todo allow it to be customized
        $emailTitle = $this->inviteDto->app->get(AppSettingsEnums::INVITE_EMAIL_SUBJECT->getValue()) ?? 'You\'ve been invited to join Kanvas Admin';

        $inviteEmail = new InviteTemplate($invite, [
            'fromUser' => $this->user,
            'subject' => $emailTitle,
            'template' => $this->inviteDto->email_template,
        ]);

        Notification::route('mail', $this->inviteDto->email)
            ->notify($inviteEmail);

        return $invite;
    }
}

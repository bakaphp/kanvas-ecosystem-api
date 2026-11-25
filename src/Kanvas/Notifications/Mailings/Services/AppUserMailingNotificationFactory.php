<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;

class AppUserMailingNotificationFactory
{
    public function __construct(
        private readonly Apps $app,
        private readonly ?Companies $company,
        private readonly ?Users $fromUser,
    ) {
    }

    public function make(
        string $templateName,
        ?string $subject,
        bool $honorMarketingPreferences,
    ): Blank {
        $notification = new Blank(
            $templateName,
            $this->options(),
            ['mail'],
            $this->entity(),
        );

        if ($honorMarketingPreferences) {
            $notification->setType(AppUserMailingEligibilityService::NOTIFICATION_TYPE);
        }

        $notification->setSubject($subject);

        if ($this->fromUser !== null) {
            $notification->setFromUser($this->fromUser);
        }

        return $notification;
    }

    public function forRecipient(Blank $notification, Users $recipient): Blank
    {
        $personalized = clone $notification;

        return $personalized->setData([
            'user' => $recipient,
            'name' => $recipient->fullName(),
        ]);
    }

    private function options(): array
    {
        return array_filter(
            ['app' => $this->app, 'company' => $this->company],
            fn (mixed $value): bool => $value !== null,
        );
    }

    private function entity(): Model
    {
        return $this->fromUser ?? $this->company ?? $this->app;
    }
}

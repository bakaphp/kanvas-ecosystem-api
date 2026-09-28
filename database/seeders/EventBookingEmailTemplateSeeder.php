<?php

declare(strict_types=1);

namespace Database\Seeders;

use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Templates\Enums\EmailTemplateEnum as GlobalEmailTemplateEnum;
use Override;

/**
 * Without these, an app that books through the Event domain (e.g. an agent scheduling an appointment)
 * fails every participant email with "Template not found" unless it hand-made its own rows.
 */
class EventBookingEmailTemplateSeeder extends GlobalEmailTemplateSeeder
{
    #[Override]
    protected function templates(): array
    {
        return [
            EmailTemplateEnum::BOOKING_CREATED->value => 'views/emails/events/booking_created.blade.php',
            EmailTemplateEnum::BOOKING_UPDATED->value => 'views/emails/events/booking_updated.blade.php',
            EmailTemplateEnum::BOOKING_CANCELLED->value => 'views/emails/events/booking_cancelled.blade.php',
            EmailTemplateEnum::BOOKING_REMINDER->value => 'views/emails/events/booking_reminder.blade.php',
        ];
    }

    #[Override]
    protected function parentTemplateName(): ?string
    {
        return GlobalEmailTemplateEnum::DEFAULT->value;
    }
}

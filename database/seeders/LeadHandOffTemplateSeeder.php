<?php

declare(strict_types=1);

namespace Database\Seeders;

use Kanvas\Intelligence\Enums\HandOffTemplateEnum;
use Override;

/**
 * Without these, a hand-off in an app that never made its own rows fails with "Template not found"
 * and nobody is told the lead asked for a person.
 *
 * No parent layout: the push has to stay the JSON it is, and the emails frame themselves because
 * not every database has a global `default` layout to sit in.
 */
class LeadHandOffTemplateSeeder extends GlobalEmailTemplateSeeder
{
    #[Override]
    protected function templates(): array
    {
        return [
            HandOffTemplateEnum::EMAIL->value => 'views/emails/leadHandOff.twig',
            HandOffTemplateEnum::PUSH->value => 'views/push/lead-handoff.twig',
            HandOffTemplateEnum::SMS->value => 'views/push/lead-handoff-sms.twig',
            HandOffTemplateEnum::DATABASE->value => 'views/push/lead-handoff-db.twig',
            HandOffTemplateEnum::COMPLIANCE_EMAIL->value => 'views/emails/leadHandOffCompliance.twig',
            HandOffTemplateEnum::COMPLIANCE_PUSH->value => 'views/push/lead-handoff-compliance.twig',
            HandOffTemplateEnum::COMPLIANCE_SMS->value => 'views/push/lead-handoff-compliance-sms.twig',
        ];
    }
}

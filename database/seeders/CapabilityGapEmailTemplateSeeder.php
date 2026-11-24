<?php

declare(strict_types=1);

namespace Database\Seeders;

use Kanvas\NervousSystem\Capability\Notifications\CapabilityGapReportedNotification;
use Override;

class CapabilityGapEmailTemplateSeeder extends GlobalEmailTemplateSeeder
{
    #[Override]
    protected function templates(): array
    {
        return [
            CapabilityGapReportedNotification::TEMPLATE_NAME => 'views/emails/nervous_system/capability_gap_reported.twig',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Providers\Summary;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Kanvas\Social\Channels\Models\Channel;
use Override;

class SocialModuleSummaryProvider implements KanvasModuleSummaryProvider
{
    #[Override]
    public function summary(Companies $company, Apps $app): array
    {
        $channels = Channel::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        return [
            'channels' => $channels,
        ];
    }
}

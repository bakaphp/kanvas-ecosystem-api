<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Services;

use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\KanvasModules\Providers\Summary\CommerceModuleSummaryProvider;
use Kanvas\KanvasModules\Providers\Summary\CrmModuleSummaryProvider;
use Kanvas\KanvasModules\Providers\Summary\InventoryModuleSummaryProvider;
use Kanvas\KanvasModules\Providers\Summary\SocialModuleSummaryProvider;
use Kanvas\KanvasModules\Providers\Summary\WorkflowModuleSummaryProvider;

class KanvasModuleSummaryProviderRegistry
{
    /**
     * Static mapping is intentional — the set of modules is closed and
     * known at compile time. Adding a new module means editing this match.
     */
    public static function for(KanvasModuleEnum $module): ?KanvasModuleSummaryProvider
    {
        return match ($module) {
            KanvasModuleEnum::CRM => new CrmModuleSummaryProvider(),
            KanvasModuleEnum::INVENTORY => new InventoryModuleSummaryProvider(),
            KanvasModuleEnum::COMMERCE => new CommerceModuleSummaryProvider(),
            KanvasModuleEnum::SOCIAL => new SocialModuleSummaryProvider(),
            KanvasModuleEnum::WORKFLOW => new WorkflowModuleSummaryProvider(),
            default => null,
        };
    }
}

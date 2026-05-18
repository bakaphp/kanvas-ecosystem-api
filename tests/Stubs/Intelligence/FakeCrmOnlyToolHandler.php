<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\NervousSystem\Capability\Contracts\DeclaresKanvasModules;
use Override;

class FakeCrmOnlyToolHandler implements DeclaresKanvasModules
{
    #[Override]
    public static function kanvasModules(): array
    {
        return [KanvasModuleEnum::CRM];
    }
}

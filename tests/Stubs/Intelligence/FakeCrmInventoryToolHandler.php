<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\NervousSystem\Capability\Contracts\DeclaresKanvasModules;
use NeuronAI\Tools\Tool;
use Override;

class FakeCrmInventoryToolHandler extends Tool implements DeclaresKanvasModules
{
    protected string $name = 'fake_crm_tool';

    #[Override]
    public static function kanvasModules(): array
    {
        return [
            KanvasModuleEnum::CRM,
            ['module' => KanvasModuleEnum::INVENTORY, 'direction' => 'both'],
        ];
    }
}

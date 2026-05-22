<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Contracts;

use Kanvas\KanvasModules\Enums\KanvasModuleEnum;

/**
 * Implemented by tool handlers that want to declare which Kanvas modules they
 * touch. The sync command mirrors the result of `kanvasModules()` into the
 * `nervous_system_tool_kanvas_modules` pivot so the agent-subscription layer
 * can find which tools (and therefore which agents) listen to which modules.
 */
interface DeclaresKanvasModules
{
    /**
     * Modules this tool reads from / writes to, with optional direction.
     *
     * Return either:
     *   - a plain list of cases:           [KanvasModuleEnum::CRM, KanvasModuleEnum::INVENTORY]
     *   - an associative shape with direction:
     *       [
     *         ['module' => KanvasModuleEnum::CRM,       'direction' => 'consumes'],
     *         ['module' => KanvasModuleEnum::INVENTORY, 'direction' => 'both'],
     *       ]
     *
     * @return array<int, KanvasModuleEnum|array{module: KanvasModuleEnum, direction?: string}>
     */
    public static function kanvasModules(): array;
}

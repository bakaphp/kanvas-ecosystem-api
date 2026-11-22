<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

#[AgentTool(name: 'List Provider Roles', category: 'movipass')]
class ListProviderRolesTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'list_provider_roles';

    protected ?string $description = 'Lista los roles de MoviPass que puedes asignar, con una explicacion en espanol de lo '
        . 'que puede hacer cada uno. Usa el valor exacto de "role" en assign_provider_user_role e '
        . 'invite_provider_user. Si la persona describe el rol con otras palabras ("operador", "gruero"), '
        . 'propon el que mejor encaje y confirma con ella.';

    protected function properties(): array
    {
        return [];
    }

    public function __invoke(): array
    {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $existing = ProviderAdminGuard::inAppScope(
            $this->app,
            fn (): array => Role::query()
                ->where('scope', RolesEnums::getScope($this->app))
                ->pluck('name')
                ->all()
        );

        $assignable = [];

        foreach (MovipassRolesEnum::cases() as $role) {
            if (in_array($role->value, $existing, true)) {
                $assignable[] = ['role' => $role->value, 'description' => $role->description()];
            }
        }

        return $this->ok(['roles' => $assignable]);
    }
}

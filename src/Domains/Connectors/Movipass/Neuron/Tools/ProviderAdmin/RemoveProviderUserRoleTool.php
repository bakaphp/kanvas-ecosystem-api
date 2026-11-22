<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Kanvas\Connectors\Movipass\Actions\ProviderAdmin\RemoveProviderUserRoleAction;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Override;

#[AgentTool(name: 'Remove Provider User Role', category: 'movipass')]
class RemoveProviderUserRoleTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'remove_provider_user_role';

    protected ?string $description = 'Quita un rol de MoviPass a un usuario. El rol de Bouncer vale para toda la app, asi '
        . 'que se quita en todas sus empresas. No sirve para administradores ni propietarios. Resuelve user_id '
        . 'con find_provider_users. La primera llamada solo muestra un resumen y un confirmation_token; repite '
        . 'la llamada con el token solo cuando la persona confirme.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'user_id',
                type: PropertyType::INTEGER,
                description: 'Id del usuario (de find_provider_users).',
                required: true,
            ),
            new ToolProperty(
                name: 'company_id',
                type: PropertyType::INTEGER,
                description: 'Id de la empresa de la que se limpia el rol asignado.',
                required: true,
            ),
            new ToolProperty(
                name: 'role',
                type: PropertyType::STRING,
                description: 'Valor exacto del rol a quitar.',
                required: true,
            ),
            new ToolProperty(
                name: 'confirmation_token',
                type: PropertyType::STRING,
                description: 'Token devuelto por la primera llamada; solo despues de que la persona confirme.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $user_id,
        int $company_id,
        string $role,
        ?string $confirmation_token = null,
    ): array {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $target = $this->findUserInApp($user_id);

        if ($target === null) {
            return $this->notFound(['executed' => false], "User {$user_id} does not exist in this app.");
        }

        $company = $this->findCompanyInApp($company_id);

        if ($company === null) {
            return $this->notFound(['executed' => false], "Company {$company_id} does not exist in this app.");
        }

        $resolvedRole = $this->resolveGrantableRole($role);

        if (! $resolvedRole instanceof MovipassRolesEnum) {
            return $resolvedRole;
        }

        if ($refusal = $this->refuseUnlessMayManageTarget($target, $company, $resolvedRole)) {
            return $refusal;
        }

        $holdsRole = ProviderAdminGuard::inAppScope(
            $this->app,
            fn (): bool => $target->isA($resolvedRole->value)
        );

        if (! $holdsRole) {
            return $this->noop(['executed' => false], "{$target->email} does not have the role {$resolvedRole->value}.");
        }

        $confirmation = $this->requireConfirmation(
            action: 'remove_role',
            arguments: [
                'user_id' => $user_id,
                'company_id' => $company_id,
                'role' => $resolvedRole->value,
            ],
            confirmationToken: $confirmation_token,
            summary: "Quitar el rol {$resolvedRole->value} a {$target->email} (empresa {$company->name}).",
        );

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            new RemoveProviderUserRoleAction(
                $this->actor(),
                $target,
                $company,
                $resolvedRole,
                $this->app,
            )->execute();
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage(), ['executed' => false]);
        }

        $this->recordAudit('role_removed', [
            'company_id' => $company->getId(),
            'target_user_id' => $target->getId(),
            'role' => $resolvedRole->value,
        ]);

        return $this->ok([
            'executed' => true,
            'user' => $target->email,
            'company' => $company->name,
            'role' => $resolvedRole->value,
        ]);
    }
}

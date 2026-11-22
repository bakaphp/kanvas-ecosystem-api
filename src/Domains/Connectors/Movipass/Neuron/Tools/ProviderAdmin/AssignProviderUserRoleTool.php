<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Kanvas\Connectors\Movipass\Actions\ProviderAdmin\AssignProviderUserRoleAction;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Override;

#[AgentTool(name: 'Assign Provider User Role', category: 'movipass')]
class AssignProviderUserRoleTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'assign_provider_user_role';

    protected ?string $description = 'Agrega un usuario que ya existe a una empresa y le asigna un rol de MoviPass. '
        . 'Resuelve user_id con find_provider_users, company_id con find_provider_companies y el rol con '
        . 'list_provider_roles. No sirve para administradores ni propietarios. El rol de Bouncer vale para toda '
        . 'la app: si el usuario esta en varias empresas conserva todos sus roles. La primera llamada solo '
        . 'muestra un resumen y un confirmation_token; repite la llamada con el token solo cuando la persona '
        . 'confirme.';

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
                description: 'Id de la empresa (de find_provider_companies).',
                required: true,
            ),
            new ToolProperty(
                name: 'role',
                type: PropertyType::STRING,
                description: 'Valor exacto del rol (de list_provider_roles).',
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

        $confirmation = $this->requireConfirmation(
            action: 'assign_role',
            arguments: [
                'user_id' => $user_id,
                'company_id' => $company_id,
                'role' => $resolvedRole->value,
            ],
            confirmationToken: $confirmation_token,
            summary: "Asignar el rol {$resolvedRole->value} ({$resolvedRole->description()}) a {$target->email} "
                . "en la empresa {$company->name}.",
        );

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            new AssignProviderUserRoleAction(
                $this->actor(),
                $target,
                $company,
                $resolvedRole,
                $this->app,
            )->execute();
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage(), ['executed' => false]);
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage(), ['executed' => false]);
        }

        $this->recordAudit('role_assigned', [
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

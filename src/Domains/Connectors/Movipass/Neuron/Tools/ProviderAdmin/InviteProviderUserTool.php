<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Baka\Support\Str;
use Kanvas\Connectors\Movipass\Actions\ProviderAdmin\InviteProviderUserAction;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Override;

#[AgentTool(name: 'Invite Provider User', category: 'movipass')]
class InviteProviderUserTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'invite_provider_user';

    protected ?string $description = 'Crea un usuario nuevo en una empresa enviandole una invitacion por correo con el rol '
        . 'indicado; la persona elige su propia contrasena, tu nunca la fijas ni la ves. Requiere el correo real. '
        . 'Primero resuelve company_id con find_provider_companies y el rol con list_provider_roles. Si el usuario '
        . 'ya existe usa assign_provider_user_role. La primera llamada solo muestra un resumen y un '
        . 'confirmation_token; muestrale el resumen a la persona y repite la llamada con el token solo cuando '
        . 'ella confirme.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'company_id',
                type: PropertyType::INTEGER,
                description: 'Id de la empresa (de find_provider_companies).',
                required: true,
            ),
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'Correo real de la persona a invitar.',
                required: true,
            ),
            new ToolProperty(
                name: 'role',
                type: PropertyType::STRING,
                description: 'Valor exacto del rol (de list_provider_roles).',
                required: true,
            ),
            new ToolProperty(
                name: 'firstname',
                type: PropertyType::STRING,
                description: 'Nombre de la persona.',
                required: false,
            ),
            new ToolProperty(
                name: 'lastname',
                type: PropertyType::STRING,
                description: 'Apellido de la persona.',
                required: false,
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
        int $company_id,
        string $email,
        string $role,
        ?string $firstname = null,
        ?string $lastname = null,
        ?string $confirmation_token = null,
    ): array {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $company = $this->findCompanyInApp($company_id);

        if ($company === null) {
            return $this->notFound(['executed' => false], "Company {$company_id} does not exist in this app.");
        }

        $email = Str::lowerTrim($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->invalidArgs('A real email address is required to send the invitation.', ['executed' => false]);
        }

        $resolvedRole = $this->resolveGrantableRole($role);

        if (! $resolvedRole instanceof MovipassRolesEnum) {
            return $resolvedRole;
        }

        if ($blocked = $this->refuseIfAlreadyKnown($email, $company_id)) {
            return $blocked;
        }

        $confirmation = $this->requireConfirmation(
            action: 'invite_user',
            arguments: [
                'company_id' => $company_id,
                'email' => $email,
                'role' => $resolvedRole->value,
                'firstname' => Str::trimToNull($firstname),
                'lastname' => Str::trimToNull($lastname),
            ],
            confirmationToken: $confirmation_token,
            summary: "Invitar a {$email} a la empresa {$company->name} con el rol {$resolvedRole->value} "
                . "({$resolvedRole->description()}). Recibira un correo para crear su contrasena.",
        );

        if ($confirmation !== null) {
            return $confirmation;
        }

        try {
            $invite = new InviteProviderUserAction(
                $this->actor(),
                $company,
                $resolvedRole,
                $email,
                $firstname,
                $lastname,
                $this->app,
            )->execute();
        } catch (AuthorizationException $e) {
            return $this->denied($e->getMessage(), ['executed' => false]);
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage(), ['executed' => false]);
        }

        $this->recordAudit('user_invited', [
            'company_id' => $company->getId(),
            'invite_id' => $invite->getId(),
            'email' => $email,
            'role' => $resolvedRole->value,
        ]);

        return $this->ok([
            'executed' => true,
            'invite_id' => $invite->getId(),
            'email' => $email,
            'company' => $company->name,
            'role' => $resolvedRole->value,
        ], 'The invitation email was sent; the person sets their own password. No password exists in this chat.');
    }

    private function refuseIfAlreadyKnown(string $email, int $companyId): ?array
    {
        $existing = Users::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing !== null && $this->findUserInApp($existing->getId()) !== null) {
            return $this->invalidArgs(
                "{$email} already has an account in this app (user id {$existing->getId()}).",
                ['executed' => false, 'user_id' => $existing->getId()],
                'Do not invite again: use assign_provider_user_role with that user_id.'
            );
        }

        if ($existing !== null) {
            return $this->failed(
                "{$email} already has a platform account outside this app, so it cannot be invited here.",
                ['executed' => false],
                'Ask an administrator to add the account to this app.'
            );
        }

        $pending = UsersInvite::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $companyId)
            ->where('email', $email)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->exists();

        return $pending
            ? $this->noop(['executed' => false], "There is already a pending invitation for {$email} in this company.")
            : null;
    }
}

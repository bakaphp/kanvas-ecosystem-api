<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Connectors\Movipass\Support\ProviderAdminGuard;
use Kanvas\Enums\StateEnums;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Find Provider Users', category: 'movipass')]
class FindProviderUsersTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'find_provider_users';

    protected ?string $description = 'Busca usuarios de esta app por nombre, apellido o correo. Devuelve hasta 10 con su id, '
        . 'las empresas a las que pertenecen y sus roles. Puedes limitar a una empresa con company_id. '
        . 'Si devuelve mas de uno, pregunta a la persona cual es; nunca elijas tu.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Nombre, apellido o correo del usuario.',
                required: true,
            ),
            new ToolProperty(
                name: 'company_id',
                type: PropertyType::INTEGER,
                description: 'Opcional: solo usuarios de esta empresa (id de find_provider_companies).',
                required: false,
            ),
        ];
    }

    public function __invoke(string $query, ?int $company_id = null): array
    {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $term = Str::trimToNull($query);

        if ($term === null) {
            return $this->invalidArgs('Say which user to look for.');
        }

        if ($company_id !== null && $this->findCompanyInApp($company_id) === null) {
            return $this->notFound(['count' => 0, 'users' => []], "Company {$company_id} does not exist in this app.");
        }

        $userIds = $this->matchingUserIds($term, $company_id);

        if ($userIds === []) {
            return $this->notFound(['count' => 0, 'users' => []], "No user matches \"{$term}\".");
        }

        $users = Users::query()->whereIn('id', $userIds)->get();

        return $this->ok([
            'count' => $users->count(),
            'ambiguous' => $users->count() > 1,
            'users' => $users->map(fn (Users $user): array => $this->describeUser($user))->all(),
        ], $users->count() > 1 ? 'Several users match: ask the person which one before acting.' : null);
    }

    private function matchingUserIds(string $term, ?int $companyId): array
    {
        $query = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->when($companyId !== null, fn ($builder) => $builder->where('companies_id', $companyId));

        return $this->scopeToNameMatch(
            $query,
            ['firstname', 'lastname', 'displayname', 'email'],
            $term,
        )
            ->distinct()
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('users_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function describeUser(Users $user): array
    {
        $companyIds = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('users_id', $user->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('companies_id', '>', 0)
            ->pluck('companies_id')
            ->all();

        return [
            'id' => $user->getId(),
            'name' => trim($user->firstname . ' ' . $user->lastname),
            'email' => $user->email,
            'companies' => Companies::query()
                ->whereIn('id', $companyIds)
                ->get()
                ->map(fn (Companies $company): array => ['id' => $company->getId(), 'name' => $company->name])
                ->all(),
            'roles' => ProviderAdminGuard::inAppScope(
                $this->app,
                fn (): array => $user->getRoles()->values()->all()
            ),
        ];
    }
}

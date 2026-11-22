<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Find Provider Companies', category: 'movipass')]
class FindProviderCompaniesTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'find_provider_companies';

    protected ?string $description = 'Busca empresas (proveedores, parqueos, gruas, aseguradoras) de esta app por nombre, '
        . 'sin importar acentos ni mayusculas. Devuelve hasta 10 candidatas con su id. Si devuelve mas de una, '
        . 'pregunta a la persona cual es; nunca elijas tu. Usa el id en las demas herramientas.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Nombre o parte del nombre de la empresa.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $query): array
    {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $term = Str::trimToNull($query);

        if ($term === null) {
            return $this->invalidArgs('Say which company to look for.');
        }

        $companies = $this->scopeToNameMatch(
            Companies::query()->whereIn('id', $this->appCompanyIds()),
            ['name'],
            $term,
        )
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        if ($companies->isEmpty()) {
            return $this->notFound(['count' => 0, 'companies' => []], "No company matches \"{$term}\".");
        }

        return $this->ok([
            'count' => $companies->count(),
            'ambiguous' => $companies->count() > 1,
            'companies' => $companies->map(fn (Companies $company): array => [
                'id' => $company->getId(),
                'name' => $company->name,
                'email' => $company->email,
                'phone' => $company->phone,
                'exact_name_match' => Str::lowerTrim($company->name) === Str::lowerTrim($term),
            ])->all(),
        ], $companies->count() > 1 ? 'Several companies match: ask the person which one before acting.' : null);
    }
}

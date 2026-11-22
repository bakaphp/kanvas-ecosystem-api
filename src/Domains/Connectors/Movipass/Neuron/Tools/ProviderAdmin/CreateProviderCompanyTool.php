<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\ProviderAdmin\CreateProviderCompanyAction;
use Kanvas\Connectors\Movipass\Neuron\Tools\Concerns\ManagesProviderAdmin;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Create Provider Company', category: 'movipass')]
class CreateProviderCompanyTool extends Tool
{
    use ManagesProviderAdmin;
    use TrackByInputs;

    protected string $name = 'create_provider_company';

    protected ?string $description = 'Crea una empresa proveedora nueva en esta app (gruas, parqueo, aseguradora...). '
        . 'Antes busca con find_provider_companies para no duplicarla. Despues de crearla agrega a su gente '
        . 'con invite_provider_user. La primera llamada solo muestra un resumen y un confirmation_token; repite '
        . 'la llamada con el token solo cuando la persona confirme.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Nombre comercial de la empresa.',
                required: true,
            ),
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'Correo de contacto de la empresa.',
                required: false,
            ),
            new ToolProperty(
                name: 'phone',
                type: PropertyType::STRING,
                description: 'Telefono de contacto de la empresa.',
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
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $confirmation_token = null,
    ): array {
        if ($denied = $this->refuseUnlessProviderAdmin()) {
            return $denied;
        }

        $name = Str::trimToNull($name);

        if ($name === null) {
            return $this->invalidArgs('The company needs a name.', ['executed' => false]);
        }

        $duplicate = Companies::query()
            ->whereIn('id', $this->appCompanyIds())
            ->whereRaw('LOWER(name) = ?', [Str::lowerTrim($name)])
            ->first();

        if ($duplicate !== null) {
            return $this->invalidArgs(
                "A company named \"{$duplicate->name}\" already exists (id {$duplicate->getId()}).",
                ['executed' => false, 'company_id' => $duplicate->getId()],
                'Do not create it again: use that company_id.'
            );
        }

        $confirmation = $this->requireConfirmation(
            action: 'create_company',
            arguments: [
                'name' => $name,
                'email' => Str::trimToNull($email),
                'phone' => Str::trimToNull($phone),
            ],
            confirmationToken: $confirmation_token,
            summary: "Crear la empresa proveedora \"{$name}\" en esta app.",
        );

        if ($confirmation !== null) {
            return $confirmation;
        }

        $company = new CreateProviderCompanyAction(
            $this->actor(),
            $name,
            Str::trimToNull($email),
            Str::trimToNull($phone),
            $this->app,
        )->execute();

        $this->recordAudit('company_created', [
            'company_id' => $company->getId(),
            'name' => $company->name,
        ]);

        return $this->ok([
            'executed' => true,
            'company_id' => $company->getId(),
            'name' => $company->name,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\IPlus\Client;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Connectors\IPlus\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;

class SavePeopleToIPlusAction
{
    protected Client $client;

    public function __construct(
        protected People $people
    ) {
        if (! $this->people->company->get(ConfigurationEnum::COMPANY_ID->value)) {
            throw new ValidationException('IPlus company ID is not set for ' . $this->people->company->name);
        }

        $this->client = new Client($this->people->app, $this->people->company);
    }

    public function execute(): string
    {
        if ($this->people->get(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value)) {
            return $this->people->get(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value);
        }

        $clientData = [
            'companiaID' => $this->people->company->get(ConfigurationEnum::COMPANY_ID->value),
            'contrasena' => Str::random(10),
            'referencia' => $this->people->app->get(ConfigurationEnum::CUSTOMER_DEFAULT_REFERENCE->value),
            'clienteNombre' => $this->people->firstname,
            'clienteApellido' => $this->people->lastname,
            'identificacion' => null,
            'direccion' => $this->people->address ? $this->people->address->address : null,
            'telCelular' => $this->people->getPhones()->count() ? $this->people->getPhones()->first()->value : null,
            'email' => $this->people->getEmails()->count() ? $this->people->getEmails()->first()->value : null,
        ];

        $createCustomer = $this->client->post(
            '/v2/Ventas/Clientes',
            $clientData
        );

        $this->people->set(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value, $createCustomer['clienteID']);

        return $createCustomer['clienteID'];
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Handlers;

use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\DataTransferObject\Acumatica as AcumaticaDto;
use Kanvas\Connectors\Acumatica\Services\AcumaticaService;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;
use Throwable;

class AcumaticaHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $dto = AcumaticaDto::from(
            $this->data,
            $this->app,
            $this->company
        );
        AcumaticaService::setup($dto);

        // REST service-account auth is required — validate it round-trips.
        $client = Client::getInstance($this->app);

        try {
            $client->login();
            $client->logout();
        } catch (Throwable $e) {
            throw new ValidationException('Acumatica REST authentication failed: ' . $e->getMessage());
        }

        // SQL read replica is optional at setup (may be provisioned later); validate only if provided.
        if (SqlClient::isConfigured($this->app)) {
            try {
                SqlClient::connection($this->app)->select('SELECT 1');
            } catch (Throwable $e) {
                throw new ValidationException('Acumatica SQL read connection failed: ' . $e->getMessage());
            }
        }

        return true;
    }
}

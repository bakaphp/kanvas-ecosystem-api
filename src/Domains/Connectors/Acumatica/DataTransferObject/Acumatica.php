<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class Acumatica extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public string $baseUrl,
        public string $username,
        public string $password,
        public string $acumaticaCompany,
        public string $endpointName = '',
        public string $endpointVersion = '23.200.001',
        public ?string $branch = null,
        public ?string $sqlHost = null,
        public ?int $sqlPort = 1433,
        public ?string $sqlDatabase = null,
        public ?string $sqlUsername = null,
        public ?string $sqlPassword = null,
    ) {
    }

    public static function fromMultiple(
        array $data,
        AppInterface $app,
        CompanyInterface $company
    ): self {
        return new self(
            company: $company,
            app: $app,
            baseUrl: (string) ($data['base_url'] ?? ''),
            username: (string) ($data['username'] ?? ''),
            password: (string) ($data['password'] ?? ''),
            acumaticaCompany: (string) ($data['company'] ?? ''),
            endpointName: (string) ($data['endpoint_name'] ?? ''),
            endpointVersion: (string) ($data['endpoint_version'] ?? '23.200.001'),
            branch: $data['branch'] ?? null,
            sqlHost: $data['sql_host'] ?? null,
            sqlPort: isset($data['sql_port']) ? (int) $data['sql_port'] : 1433,
            sqlDatabase: $data['sql_database'] ?? null,
            sqlUsername: $data['sql_username'] ?? null,
            sqlPassword: $data['sql_password'] ?? null,
        );
    }

    public function toConfig(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'username' => $this->username,
            'password' => $this->password,
            'acumaticaCompany' => $this->acumaticaCompany,
            'endpointName' => $this->endpointName,
            'endpointVersion' => $this->endpointVersion,
            'branch' => $this->branch,
            'sqlHost' => $this->sqlHost,
            'sqlPort' => $this->sqlPort,
            'sqlDatabase' => $this->sqlDatabase,
            'sqlUsername' => $this->sqlUsername,
            'sqlPassword' => $this->sqlPassword,
        ];
    }
}

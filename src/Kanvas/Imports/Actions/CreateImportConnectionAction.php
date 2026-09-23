<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Validations\ImportConnectionValidation;

class CreateImportConnectionAction
{
    public function __construct(
        private readonly ImportConnectionData $data,
    ) {
    }

    public function execute(): ImportConnection
    {
        if ($this->data->password === null) {
            throw new ValidationException('password is required.');
        }

        ImportConnectionValidation::assertValid($this->data);

        return ImportConnection::create([
            ...$this->data->attributes(),
            'apps_id' => $this->data->app->getId(),
            'companies_id' => $this->data->company?->getId() ?? 0,
            'users_id' => $this->data->user->getId(),
            'password' => $this->data->password,
        ]);
    }
}

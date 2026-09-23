<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Validations\ImportConnectionValidation;

class UpdateImportConnectionAction
{
    public function __construct(
        private readonly ImportConnection $connection,
        private readonly ImportConnectionData $data,
    ) {
    }

    public function execute(): ImportConnection
    {
        ImportConnectionValidation::assertValid($this->data);

        $this->connection->fill($this->data->attributes());

        if ($this->data->password !== null) {
            $this->connection->password = $this->data->password;
        }

        $this->connection->save();

        return $this->connection;
    }
}

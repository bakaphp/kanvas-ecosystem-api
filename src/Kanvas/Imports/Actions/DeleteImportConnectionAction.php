<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Models\ImportSource;

class DeleteImportConnectionAction
{
    public function __construct(
        private readonly ImportConnection $connection,
    ) {
    }

    public function execute(): bool
    {
        $inUse = ImportSource::query()
            ->where('import_connections_id', $this->connection->getId())
            ->where('is_active', true)
            ->notDeleted()
            ->count();

        if ($inUse > 0) {
            throw new ValidationException(sprintf(
                'This connection is used by %d active scheduled import(s). Deactivate or move them first.',
                $inUse
            ));
        }

        return $this->connection->softDelete();
    }
}

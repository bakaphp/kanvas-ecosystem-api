<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Actions\CreateImportConnectionAction;
use Kanvas\Imports\Actions\DeleteImportConnectionAction;
use Kanvas\Imports\Actions\TestImportConnectionAction;
use Kanvas\Imports\Actions\UpdateImportConnectionAction;
use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Imports\Models\ImportConnection;

/**
 * Company admins manage their company's own connections. App-wide connections are used here but
 * created and edited only with `kanvas:imports:create-connection`, so one company can't change the
 * login every other company downloads from.
 */
class ImportConnectionMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): ImportConnection
    {
        $ctx = $this->actingContext();

        return new CreateImportConnectionAction(
            ImportConnectionData::from(
                $ctx->app,
                $this->company(),
                $ctx->user,
                $request['input']
            )
        )->execute();
    }

    public function update(mixed $rootValue, array $request): ImportConnection
    {
        $connection = $this->ownConnection((int) $request['id']);

        return new UpdateImportConnectionAction(
            $connection,
            ImportConnectionData::forUpdate($connection, $this->actingContext()->user, $request['input'])
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        return new DeleteImportConnectionAction($this->ownConnection((int) $request['id']))->execute();
    }

    public function test(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        if (isset($request['id'])) {
            $connection = ImportConnection::getUsableById((int) $request['id'], $ctx->app, $this->company());
        } elseif (isset($request['input'])) {
            $data = ImportConnectionData::from(
                $ctx->app,
                $this->company(),
                $ctx->user,
                $request['input']
            );
            $connection = new ImportConnection([
                'driver' => $data->driver,
                'host' => $data->host,
                'port' => $data->resolvedPort(),
                'username' => $data->username,
                'password' => $data->password,
                'root' => $data->root,
                'passive' => $data->passive,
            ]);
        } else {
            throw new ValidationException('Pass either id or input.');
        }

        return new TestImportConnectionAction($connection, $request['root'] ?? null)->execute();
    }

    private function ownConnection(int $id): ImportConnection
    {
        $ctx = $this->actingContext();

        /** @var ImportConnection $connection */
        $connection = ImportConnection::getByIdFromCompanyApp($id, $this->company(), $ctx->app);

        return $connection;
    }

    private function company(): Companies
    {
        /** @var Companies $company */
        $company = $this->actingContext()->company;

        return $company;
    }
}

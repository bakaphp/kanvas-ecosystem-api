<?php

declare(strict_types=1);

namespace Kanvas\Imports\Models;

use Baka\Contracts\AppInterface;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Kanvas\Models\BaseModel;
use Override;

/**
 * An FTP/SFTP login that scheduled imports download from. `companies_id = 0` rows are app-wide:
 * one provider login (e.g. the dealer-feed FTP) shared by every company's imports, so rotating its
 * password is one edit. That is the only reason this entity ships global rows.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property ImportDriverEnum $driver
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string $password
 * @property string|null $root
 * @property bool $passive
 * @property string|null $default_schedule
 * @property string|null $timezone
 */
class ImportConnection extends BaseModel
{
    use UuidTrait;

    protected $table = 'import_connections';

    protected $guarded = [];

    protected $hidden = ['password'];

    #[Override]
    protected function casts(): array
    {
        return [
            'driver' => ImportDriverEnum::class,
            'password' => 'encrypted',
            'passive' => 'boolean',
            'port' => 'integer',
            'companies_id' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * The company's own connections plus the app-wide ones. Not `fromCompanyOrGlobal()`: that one only
     * includes global rows when a Souk cross-company flag is on, which would hide app-wide logins.
     */
    public function scopeUsableBy(Builder $query, mixed $company = null): Builder
    {
        // @paginate passes the query arguments here, so anything but a company means "the caller's".
        $company = $company instanceof Companies ? $company : auth()->user()->getCurrentCompany();

        return $query->whereIn('companies_id', [0, $company->getId()]);
    }

    public static function getUsableById(int $id, AppInterface $app, Companies $company): self
    {
        $connection = self::query()
            ->where('id', $id)
            ->where('apps_id', $app->getId())
            ->usableBy($company)
            ->notDeleted()
            ->first();

        if (! $connection instanceof self) {
            throw new ValidationException('Connection ' . $id . ' does not exist or is not usable by this company.');
        }

        return $connection;
    }

    public function isAppWide(): bool
    {
        return $this->companies_id === 0;
    }
}

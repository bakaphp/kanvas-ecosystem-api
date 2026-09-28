<?php

declare(strict_types=1);

namespace Kanvas\Imports\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Kanvas\Imports\Models\ImportConnection;
use Spatie\LaravelData\Data;

class ImportConnectionData extends Data
{
    /**
     * @param Companies|null $company null = app-wide, usable by every company in the app
     * @param string|null $password null on update keeps the stored password
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly ?Companies $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ImportDriverEnum $driver,
        public readonly string $host,
        public readonly string $username,
        public readonly ?string $password,
        public readonly ?int $port = null,
        public readonly ?string $root = null,
        public readonly bool $passive = true,
        public readonly ?string $defaultSchedule = null,
        public readonly ?string $timezone = null,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        ?Companies $company,
        UserInterface $user,
        array $input
    ): self {
        foreach (['name', 'host', 'username'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                throw new ValidationException($required . ' is required.');
            }
        }

        $driver = ($input['driver'] ?? null) instanceof ImportDriverEnum
            ? $input['driver']
            : ImportDriverEnum::tryFrom(strtolower((string) ($input['driver'] ?? '')));

        if ($driver === null) {
            throw new ValidationException('driver must be ftp or sftp.');
        }

        $password = $input['password'] ?? null;

        return new self(
            app: $app,
            company: $company,
            user: $user,
            name: trim((string) $input['name']),
            driver: $driver,
            host: trim((string) $input['host']),
            username: (string) $input['username'],
            password: $password === null || $password === '' ? null : (string) $password,
            port: isset($input['port']) ? (int) $input['port'] : null,
            root: $input['root'] ?? null,
            passive: (bool) ($input['passive'] ?? true),
            defaultSchedule: $input['default_schedule'] ?? null,
            timezone: $input['timezone'] ?? null,
        );
    }

    public static function forUpdate(ImportConnection $connection, UserInterface $user, array $input): self
    {
        return self::fromMultiple(
            $connection->app,
            $connection->company,
            $user,
            array_merge(
                [
                    'name' => $connection->name,
                    'driver' => $connection->driver,
                    'host' => $connection->host,
                    'port' => $connection->port,
                    'username' => $connection->username,
                    'root' => $connection->root,
                    'passive' => $connection->passive,
                    'default_schedule' => $connection->default_schedule,
                    'timezone' => $connection->timezone,
                ],
                $input
            )
        );
    }

    /**
     * The columns create and update both write; the password is handled by each, since update keeps it when null.
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->resolvedPort(),
            'username' => $this->username,
            'root' => $this->root,
            'passive' => $this->passive,
            'default_schedule' => $this->defaultSchedule,
            'timezone' => $this->timezone,
        ];
    }

    public function resolvedPort(): int
    {
        return $this->port ?? $this->driver->defaultPort();
    }
}

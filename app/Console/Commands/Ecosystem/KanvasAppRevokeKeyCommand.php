<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;

class KanvasAppRevokeKeyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:app-key-revoke
                            {app_id}
                            {--key= : client_id or client_secret_id of the key}
                            {--name= : name of the key}
                            {--expires-at= : schedule the revocation instead of revoking now (Y-m-d H:i:s)}
                            {--rotate : issue a new secret for the key instead of revoking it}
                            {--list : list the app keys and exit}
                            {--force : skip the confirmation prompt}';

    protected $description = 'Revoke a Kanvas App Key, immediately, on a given date, or by rotating its secret';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        if ($this->option('list')) {
            $this->listKeys($app);

            return self::SUCCESS;
        }

        $key = $this->resolveKey($app);

        if ($key === null) {
            $this->error('No active app key found for app ' . $app->getId() . ' with the given --key/--name.');

            return self::FAILURE;
        }

        if ($this->option('expires-at') !== null) {
            return $this->expireKey($key);
        }

        return $this->option('rotate')
            ? $this->rotateKey($key)
            : $this->revokeKey($key);
    }

    private function listKeys(Apps $app): void
    {
        $keys = AppKey::where('apps_id', $app->getId())->get();

        $this->table(
            ['client_id', 'name', 'secret (masked)', 'last used', 'expires at', 'revoked'],
            $keys->map(fn (AppKey $key) => [
                $key->client_id,
                $key->name,
                substr((string) $key->client_secret_id, 0, 6) . '…',
                $key->last_used_date ?? '-',
                $key->expires_at ?? '-',
                $key->is_deleted ? 'yes' : 'no',
            ])->all()
        );
    }

    private function resolveKey(Apps $app): ?AppKey
    {
        $identifier = $this->option('key');
        $name = $this->option('name');

        if ($identifier === null && $name === null) {
            return null;
        }

        $query = AppKey::notDeleted()->where('apps_id', $app->getId());

        if ($identifier !== null) {
            $query->where(
                fn ($query) => $query->where('client_id', $identifier)
                    ->orWhere('client_secret_id', $identifier)
            );
        }

        if ($name !== null) {
            $query->where('name', $name);
        }

        return $query->first();
    }

    private function expireKey(AppKey $key): int
    {
        try {
            $expiresAt = Carbon::parse((string) $this->option('expires-at'));
        } catch (InvalidFormatException $e) {
            $this->error('Could not parse --expires-at: ' . $e->getMessage());

            return self::FAILURE;
        }

        $key->expires_at = $expiresAt->toDateTimeString();
        $key->saveOrFail();

        $this->info('App key ' . $this->label($key) . ' expires at ' . $key->expires_at . '.');

        return self::SUCCESS;
    }

    private function rotateKey(AppKey $key): int
    {
        if (! $this->confirmed('Rotate the secret of app key ' . $this->label($key) . '?')) {
            return self::SUCCESS;
        }

        $key->client_secret_id = Str::random(128);
        $key->saveOrFail();

        $this->info('App key ' . $this->label($key) . ' rotated, the previous secret no longer authenticates.');
        $this->newLine();
        $this->info('Secret: ' . $key->client_secret_id);

        return self::SUCCESS;
    }

    /**
     * apps_keys is keyed on (apps_id, users_id), so a revoked row still holds that user's slot.
     */
    private function revokeKey(AppKey $key): int
    {
        if (! $this->confirmed('Revoke app key ' . $this->label($key) . '? Any client using it stops authenticating immediately.')) {
            return self::SUCCESS;
        }

        $key->softDelete();

        $this->info('App key ' . $this->label($key) . ' revoked.');
        $this->warn('Reissuing a key to ' . $key->user->email . ' on this app requires --rotate, the revoked row still holds their slot.');

        return self::SUCCESS;
    }

    private function label(AppKey $key): string
    {
        return $key->client_id . ' (' . $key->name . ')';
    }

    private function confirmed(string $question): bool
    {
        if ($this->option('force') || $this->confirm($question)) {
            return true;
        }

        $this->info('Aborted, nothing changed.');

        return false;
    }
}

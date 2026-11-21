<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $client_id
 * @property int $apps_id
 * @property string $ip
 * @property ?string $user_agent
 * @property string $first_seen_at
 * @property string $last_seen_at
 * @property bool $sentry_alert_pending
 * @property bool $email_alert_pending
 */
class AppKeySource extends Model
{
    use MassPrunable;

    public const int RETENTION_DAYS = 90;

    protected $table = 'apps_keys_sources';
    protected $connection = 'ecosystem';
    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sentry_alert_pending' => 'boolean',
            'email_alert_pending' => 'boolean',
        ];
    }

    public function hasPendingAlert(): bool
    {
        return $this->sentry_alert_pending || $this->email_alert_pending;
    }

    public function scopeWithPendingAlert(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $query) => $query->where('sentry_alert_pending', true)->orWhere('email_alert_pending', true)
        );
    }

    /**
     * A key shipped in a public frontend gets a row per visitor IP, so sources not seen lately are
     * dropped. One still owed an alert is kept: pruning it would lose the alert for good.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('last_seen_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->where('sentry_alert_pending', false)
            ->where('email_alert_pending', false);
    }
}

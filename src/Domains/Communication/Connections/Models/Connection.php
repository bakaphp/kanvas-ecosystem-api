<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Models\BaseModel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * One account a company communicates through: a phone number, a mailbox, a paired session.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $integrations_company_id
 * @property string $provider
 * @property string $account_key
 * @property array|null $config
 * @property SetupStatusEnum $setup_status
 * @property bool $enabled
 * @property int $activation_version
 * @property string $health
 * @property string|null $last_error
 * @property array|null $provisioning what setup created and recorded, read back by repair and cleanup
 * @property int $setup_attempts
 * @property Carbon|null $next_setup_at
 * @property Carbon|null $first_ready_at
 * @property Carbon|null $disconnect_requested_at
 */
class Connection extends BaseModel
{
    use UuidTrait;

    /** `config` key a provider sets on an account that is one agent's own address. */
    public const string PERSONAL = 'personal';

    /** The two states a disconnect leads to; in every other one provisioning still answers for the row. */
    private const array RETIRED = [SetupStatusEnum::DISCONNECTING, SetupStatusEnum::DISCONNECTED];

    /** @var array<int, Agent> the company's agents already looked up for this row, by id */
    private array $ownAgents = [];

    protected $table = 'communication_connections';

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'config' => Json::class,
            'provisioning' => Json::class,
            'setup_status' => SetupStatusEnum::class,
            'enabled' => 'boolean',
            'activation_version' => 'integer',
            'is_deleted' => 'boolean',
            'last_probed_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'disconnect_requested_at' => 'datetime',
            'next_setup_at' => 'datetime',
            'first_ready_at' => 'datetime',
            'setup_attempts' => 'integer',
        ];
    }

    public function integrationCompany(): BelongsTo
    {
        return $this->belongsTo(IntegrationsCompany::class, 'integrations_company_id');
    }

    public function claim(): HasOne
    {
        return $this->hasOne(InboundClaim::class, 'connection_id');
    }

    public function cleanupTasks(): HasMany
    {
        return $this->hasMany(CleanupTask::class, 'connection_id');
    }

    /**
     * The connection a filed message arrived on or left through, or null for a message from before
     * connections existed. Bounded to the message's own tenant: the id is ours, but the row it names
     * must still belong to the workspace the message was filed in.
     */
    public static function forFiledMessage(Message $message): ?self
    {
        $connectionId = $message->message['communication']['connection_id'] ?? null;

        if ($connectionId === null) {
            return null;
        }

        return self::query()
            ->fromApp((int) $message->apps_id)
            ->where('companies_id', (int) $message->companies_id)
            ->find((int) $connectionId);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('setup_status', array_column(self::RETIRED, 'value'));
    }

    public function scopeOfIntegration(Builder $query, IntegrationsCompany|int $integrationCompany): Builder
    {
        return $query->where(
            'integrations_company_id',
            $integrationCompany instanceof IntegrationsCompany ? $integrationCompany->getId() : $integrationCompany
        );
    }

    /**
     * The agent that owns this number or mailbox, and so answers what arrives on it. A connection
     * nobody owns is received and filed, never answered.
     */
    public function agent(): ?Agent
    {
        return $this->ownAgent((int) ($this->config['agent_id'] ?? 0));
    }

    /**
     * The agent whose own address this is, as opposed to a company line an agent answers. Only a
     * provider that tells the two apart says so (`config.personal`); a phone number never does.
     */
    public function personalOwner(): ?Agent
    {
        return ($this->config[self::PERSONAL] ?? false) ? $this->agent() : null;
    }

    /**
     * An agent sending from its own address writes as a person does; from any other it sends the
     * company's message.
     */
    public function isPersonalAddressOf(?Agent $agent): bool
    {
        return $agent !== null
            && ($this->config[self::PERSONAL] ?? false)
            && (int) ($this->config['agent_id'] ?? 0) === (int) $agent->getId();
    }

    /**
     * The agent a retired rule named, when this connection can be handed to it: only a connection
     * nobody owns, and only to an agent of its own company. An owner an agent already declared is
     * never overridden.
     */
    public function candidateOwner(int $agentId): ?Agent
    {
        return $this->agent() === null ? $this->ownAgent($agentId) : null;
    }

    /**
     * Only ever an agent of the connection's own company: an id from a config or a retired rule is
     * not proof of that.
     */
    private function ownAgent(int $agentId): ?Agent
    {
        if ($agentId === 0) {
            return null;
        }

        return $this->ownAgents[$agentId] ??= Agent::query()
            ->fromApp((int) $this->apps_id)
            ->fromCompany((int) $this->companies_id)
            ->notDeleted()
            ->whereKey($agentId)
            ->first();
    }

    /**
     * Takes effect now; the provider's `accounts()` must report the same owner on the next setup,
     * or it is reset there.
     *
     * @param array<string, mixed> $attributes other account attributes that go with the owner
     */
    public function handTo(Agent $agent, array $attributes = []): void
    {
        $this->forceFill([
            'config' => [
                ...($this->config ?? []),
                ...$attributes,
                'agent_id' => $agent->getId(),
            ],
        ])->saveOrFail();
    }

    /**
     * Carried by something the company has without integrating a vendor (its own SMTP), so there is
     * no integration row whose switch could turn it off.
     */
    public function isBuiltIn(): bool
    {
        return (int) $this->integrations_company_id === 0;
    }

    public function isLive(): bool
    {
        return ! in_array($this->setup_status, self::RETIRED, true);
    }

    /**
     * The lock its vendor account is registered and cleaned up under. Named by provider and
     * account, never by row: a number that moved to another workspace is still one vendor resource,
     * so the new owner's registration and the old owner's cleanup never interleave.
     */
    public function resourceLockKey(): string
    {
        return "communication:resource:{$this->provider}:{$this->account_key}";
    }

    /**
     * Why this connection cannot carry a message right now, or null when it can. Setup, the user's
     * switch and the integration's switch are three separate facts, and none of them is ever worked
     * around by sending through another connection.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->isLive() => 'connection_disconnected',
            $this->setup_status !== SetupStatusEnum::READY => 'connection_not_ready',
            ! $this->enabled => 'connection_disabled',
            ! $this->isBuiltIn() && ! $this->integrationCompany?->is_active => 'integration_inactive',
            default => null,
        };
    }

    /**
     * Asked at the moment queued work would use the connection: a delivery about to reach the
     * provider, an agent about to answer. The work remembers the activation version it was queued
     * under; a connection switched off since, or off and on again, cancelled it.
     *
     * @param int|null $activationVersion null when the work recorded none, and then only the
     *                                    connection's state decides
     *
     * @return string|null why the work must not go out, or null when it may
     */
    public function refusalAt(?int $activationVersion): ?string
    {
        // Switching back on must not quietly release what the switch-off cancelled.
        return $this->unavailableReason() ?? (
            $activationVersion !== null && $activationVersion !== $this->activation_version
                ? 'stale_activation'
                : null
        );
    }
}

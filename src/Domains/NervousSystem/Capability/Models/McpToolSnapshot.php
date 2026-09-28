<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Last known good `tools/list` for one agent's connection to one MCP server — the durable tier behind
 * Redis. Per agent because each agent signs in with its own credential, and what a server exposes
 * depends on who is asking.
 *
 * Append-only-ish: there is no `is_deleted` column, so `KanvasModelTrait`'s static lookups
 * (`getById`, `getByIdFromCompanyApp`) will error — they call `notDeleted()`. Read with
 * `query()->where(...)` instead.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agents_id
 * @property int $integrations_id
 * @property array $payload
 * @property string $payload_hash
 * @property int $tool_count
 * @property Carbon|null $fetched_at
 */
class McpToolSnapshot extends Model
{
    use KanvasModelTrait;

    protected $connection = 'intelligence';

    protected $table = 'nervous_system_mcp_tool_snapshots';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agents_id' => 'integer',
            'integrations_id' => 'integer',
            'payload' => Json::class,
            'tool_count' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Order-sensitive: callers pass the list already sorted (McpConnectionService::fetchDescriptors),
     * so the same tools from a vendor in a different order hash the same and are not rewritten.
     *
     * @param array<int, array<string, mixed>> $descriptors
     */
    public static function hashFor(array $descriptors): string
    {
        return sha1((string) json_encode($descriptors));
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Last known good `tools/list` for one (company, MCP server) pair — the durable tier behind Redis.
 *
 * Append-only-ish: there is no `is_deleted` column, so `KanvasModelTrait`'s static lookups
 * (`getById`, `getByIdFromCompanyApp`) will error — they call `notDeleted()`. Read with
 * `query()->fromApp($app)->...` instead.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
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
            'integrations_id' => 'integer',
            'payload' => Json::class,
            'tool_count' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Sorted before hashing so a vendor returning the same tools in a different order does not read as
     * a change — an unstable tool list rewrites the LLM prompt prefix and throws away the provider's
     * prompt cache every turn.
     *
     * @param array<int, array<string, mixed>> $descriptors
     */
    public static function hashFor(array $descriptors): string
    {
        return sha1((string) json_encode($descriptors));
    }

    public function isOlderThan(int $seconds): bool
    {
        return $this->fetched_at === null
            || $this->fetched_at->lt(Carbon::now()->subSeconds($seconds));
    }
}

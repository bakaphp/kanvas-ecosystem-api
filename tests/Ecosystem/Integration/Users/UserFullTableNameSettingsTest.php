<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Message::user() hydrates a UserFullTableName; its settings must be the same ones the Users row has.
 */
class UserFullTableNameSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private ?Users $user = null;

    private ?string $key = null;

    protected function tearDown(): void
    {
        // Settings are mirrored to Redis, which the transaction does not roll back.
        if ($this->user !== null && $this->key !== null) {
            Redis::hDel($this->user->getSettingsRedisPrimaryKey(), $this->key);
        }

        parent::tearDown();
    }

    public function testSharesTheSettingsCacheKeyWithUsers(): void
    {
        $user = auth()->user();
        $full = UserFullTableName::query()->findOrFail($user->getId());

        $this->assertSame($user->getSettingsRedisPrimaryKey(), $full->getSettingsRedisPrimaryKey());
    }

    public function testReadsASettingSavedThroughUsersEvenAfterACachedMiss(): void
    {
        $this->user = $user = auth()->user();
        $full = UserFullTableName::query()->findOrFail($user->getId());
        $this->key = $key = 'email_signature_' . uniqid();

        // A miss on the full-table-name instance caches a "not found" marker in Redis.
        $this->assertNull($full->get($key));

        $user->set($key, ['append' => true, 'text' => 'Sales Rep']);

        $value = UserFullTableName::query()->findOrFail($user->getId())->get($key);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $this->assertSame(['append' => true, 'text' => 'Sales Rep'], $value);
    }
}

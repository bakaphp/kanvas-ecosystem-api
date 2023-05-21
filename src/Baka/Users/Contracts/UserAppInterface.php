<?php

declare(strict_types=1);

namespace Baka\Users\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface UserAppInterface
{
    public function user(): BelongsTo;

    public function company(): BelongsTo;

    public function app(): BelongsTo;

    public function set(string $key, mixed $value): void;

    public function get(string $key): mixed;

    public function isActive(): bool;

    public function isBanned(): bool;
}

<?php

declare(strict_types=1);

namespace Baka\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

interface CompanyInterface extends CustomFieldInterface
{
    public function getId(): mixed;

    public function getUuid(): string;

    public function branches(): HasMany;

    public function defaultBranch(): HasOne;

    public function branch(): HasOne;

    public function groups(): BelongsToMany;

    public function user(): BelongsTo;
}

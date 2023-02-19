<?php

declare(strict_types=1);

namespace Baka\Users\Contracts;

use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UsersAssociatedApps;

interface UserInterface extends Authenticatable
{
    public static function getByEmail(string $email): self;

    public function getId(): int;

    public function getUuid(): string;

    public function isActive(): bool;

    public function isBanned(): bool;

    public function getEmail(): string;

    public function defaultCompany(): HasOne;

    public function apps(): HasManyThrough;

    public function companies(): HasManyThrough;

    public function branches(): HasManyThrough;

    public function notifications(): HasMany;

    public function currentAppInfo(): UsersAssociatedApps;

    public function getCurrentCompany(): CompanyInterface;
}

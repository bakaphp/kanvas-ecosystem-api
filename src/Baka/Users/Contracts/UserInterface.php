<?php

declare(strict_types=1);

namespace Baka\Users\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
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

    public function getAppProfile(AppInterface $app): UsersAssociatedApps;

    public function getCurrentCompany(): CompanyInterface;

    //@todo user a branch interface
    public function getCurrentBranch(): CompaniesBranches;

    public function isAppOwner(): bool;
}

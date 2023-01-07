<?php

declare(strict_types=1);

namespace Baka\Users\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * EnumsInterface.
 */
interface UserInterface extends Authenticatable
{
    public static function getByEmail(string $email) : self;
    public function getId() : int;
    public function getUuid() : string;
    public function isActive() : bool;
    public function isBanned() : bool;
    public function getEmail() : string;
    public function defaultCompany() : HasOne;
    public function companies() : HasMany;
    public function branches() : HasMany;
    public function notifications() : HasMany;

}

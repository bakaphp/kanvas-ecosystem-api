<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use Kanvas\Sessions\Models\Sessions;
use Laravel\Sanctum\HasApiTokens as SanctumHasApiTokens;

trait HasApiTokens
{
    use SanctumHasApiTokens, HasJwtToken{
        HasJwtToken::createToken insteadof  SanctumHasApiTokens;
    }

    /**
     * Get the access tokens that belong to model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function tokens()
    {
        return $this->hasMany(Sessions::class, 'users_id', 'id');
    }

    /**
     * Determine if the current API token has a given scope.
     *
     * @param  string  $ability
     *
     * @return bool
     */
    public function tokenCan(string $ability)
    {
        return $this->accessToken && $this->accessToken->can($ability);
    }

    /**
     * Get the access token currently associated with the user.
     *
     * @return \Laravel\Sanctum\Contracts\HasAbilities
     */
    public function currentAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set the current access token for the user.
     *
     * @param  \Laravel\Sanctum\Contracts\HasAbilities  $accessToken
     *
     * @return $this
     */
    public function withAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }
}

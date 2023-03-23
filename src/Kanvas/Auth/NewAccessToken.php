<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Kanvas\Sessions\Models\Sessions;

/**
 * AppsData class.
 */
class NewAccessToken
{
    /**
     * Constructor.
     *
     * @param Sessions $sessionToken
     */
    public function __construct(
        protected Sessions $sessionToken
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'sessionId' => $this->sessionToken->id,
            'token' => $this->sessionToken->token,
            'refresh_token' => $this->sessionToken->refresh_token,
            'token_expires' => $this->sessionToken->expires_at->format('Y-m-d H:i:s'),
            'refresh_token_expires' => $this->sessionToken->refresh_token_expires_at->format('Y-m-d H:i:s'),
            'time' => $this->sessionToken->time,
            'timezone' => $this->sessionToken->user->timezone,
            'id' => $this->sessionToken->users_id,
            'uuid' => $this->sessionToken->user->uuid,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int $options
     *
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode(
            $this->toArray(),
            $options
        );
    }
}

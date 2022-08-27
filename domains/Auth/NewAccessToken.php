<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Kanvas\Sessions\Sessions\Models\Sessions;

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
            'token_expires' => date('Y-m-d H:i:s', $this->sessionToken->expires_at),
            'refresh_token_expires' =>  date('Y-m-d H:i:s', $this->sessionToken->refresh_token_expires_at),
            'time' => $this->sessionToken->time,
            'timezone' => $this->user->timezone,
            'id' => $this->user->id
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

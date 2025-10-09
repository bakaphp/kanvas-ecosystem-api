<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Intelligence\Sessions\Models\Session;

class UpdateSessionAction
{
    public function __construct(private Session $session)
    {
    }

    public function execute(): void
    {
        $agent = $this->session->agent;
        $agent->chat(
            $this->session->channel,
            $this->session->channel->getLastMessage(),
            'Update your session',
            function ($message) {
                // Handle the last message
            },
            $this->session
        );
    }
}

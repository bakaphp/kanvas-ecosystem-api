<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;

trait FailsBroadcasts
{
    private const BROADCAST_FAILURE = 'Pusher is down';

    protected function failBroadcastsNamed(string $failing): void
    {
        $failure = self::BROADCAST_FAILURE;

        Broadcast::extend('failing', function () use ($failing, $failure): Broadcaster {
            return new class ($failing, $failure) implements Broadcaster {
                public function __construct(
                    private readonly string $failing,
                    private readonly string $failure,
                ) {
                }

                public function auth($request): void
                {
                }

                public function validAuthenticationResponse($request, $result): void
                {
                }

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    if ($event === $this->failing) {
                        throw new RuntimeException($this->failure);
                    }
                }
            };
        });
        config([
            'broadcasting.default' => 'failing',
            'broadcasting.connections.failing' => ['driver' => 'failing'],
        ]);
    }

    protected function assertBroadcastFailureReported(): void
    {
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === self::BROADCAST_FAILURE);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

/**
 * Who is speaking. It is recorded on the request and on the filed message; it does not choose the
 * connection — that is routing, and an agent has no number of its own.
 */
final readonly class Sender
{
    private function __construct(
        public string $type,
        public ?int $id = null
    ) {
    }

    public static function system(): self
    {
        return new self('system');
    }

    public static function agent(Agent $agent): self
    {
        return new self('agent', $agent->getId());
    }

    public static function user(Users $user): self
    {
        return new self('user', $user->getId());
    }

    public function isAgent(): bool
    {
        return $this->type === 'agent';
    }

    /**
     * @return array{type: string, id: int|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}

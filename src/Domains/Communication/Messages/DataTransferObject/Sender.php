<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

/**
 * Who is speaking. It is recorded on the request and on the filed message. Routing reads it too: an
 * agent that owns a number or a mailbox speaks from it.
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

    /**
     * The agent when one wrote it, else the user who sent it, else the platform.
     */
    public static function speaking(?Agent $agent, mixed $user = null): self
    {
        return match (true) {
            $agent !== null => self::agent($agent),
            $user instanceof Users => self::user($user),
            default => self::system(),
        };
    }

    /**
     * @param array{type?: string, id?: int|null}|null $sender as `toArray()` stored it on a request
     */
    public static function fromArray(?array $sender): self
    {
        return new self((string) ($sender['type'] ?? 'system'), $sender['id'] ?? null);
    }

    public function isAgent(): bool
    {
        return $this->type === 'agent';
    }

    public function isUser(): bool
    {
        return $this->type === 'user';
    }

    /**
     * @return array{type: string, id: int|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}

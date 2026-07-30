<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

/**
 * The persona block for a customer-facing agent: it knows its own NAME (from its assigned Kanvas
 * user, falling back to the agent name) so it introduces itself consistently — but no internal
 * identifier (user id, email, lead/database id) ever goes into the prompt, and it's told never to
 * reveal one. The prospect only needs a name. Consumed by agents that implement ConversesWithCustomer.
 *
 * Requires the composing class to expose the BaseKanvasAgent `$agent` and `$company` properties.
 */
trait HasCustomerPersona
{
    /**
     * @return list<string>
     */
    protected function personaLines(): array
    {
        $name = $this->personaName();

        if ($name === '') {
            return [];
        }

        $company = $this->company?->name;

        return [
            'You are ' . $name . ($company !== null && $company !== '' ? ', a representative at ' . $company : '') . '.',
            'Introduce yourself by name when it helps rapport — to the customer you ARE ' . $name . '.',
            'NEVER reveal internal system identifiers to the customer: no user id, email, lead id or any '
                . 'database id. The customer only ever needs your name.',
        ];
    }

    protected function personaName(): string
    {
        $user = $this->agent?->user;

        if ($user !== null) {
            $name = trim($user->firstname . ' ' . $user->lastname);

            if ($name !== '') {
                return $name;
            }

            if ($user->displayname !== null && $user->displayname !== '') {
                return $user->displayname;
            }
        }

        return (string) ($this->agent?->name ?? '');
    }
}

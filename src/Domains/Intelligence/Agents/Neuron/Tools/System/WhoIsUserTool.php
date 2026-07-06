<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Who Is User')]
class WhoIsUserTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly ?Users $currentUser = null,
    ) {
        parent::__construct(
            name: 'who_is_user',
            description: 'Find out who you are talking to, or look up another teammate by id OR by their @displayname/handle — '
                . 'their name, email and company. Use it whenever someone refers to a teammate by a handle (e.g. "kaioken", "@jane").',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'user_id',
                type: PropertyType::INTEGER,
                description: 'The id of a user in this company to describe. Omit to describe the person you are currently talking to.',
                required: false,
            ),
            new ToolProperty(
                name: 'handle',
                type: PropertyType::STRING,
                description: 'The @displayname/handle of a teammate (e.g. "kaioken" or "@jane"). Use when someone refers to a user by handle rather than id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $user_id = null, ?string $handle = null): array
    {
        $user = match (true) {
            $user_id !== null => $this->resolveUser($user_id),
            $handle !== null && trim($handle) !== '' => $this->resolveByHandle($handle),
            default => $this->currentUser,
        };

        if ($user === null) {
            return [
                'status' => 'error',
                'message' => 'No user in scope. Pass a user_id or a @displayname/handle of a user in this company.',
            ];
        }

        return [
            'id' => $user->getId(),
            'name' => trim($user->firstname . ' ' . $user->lastname),
            'displayname' => $user->displayname,
            'email' => $user->email,
            'company' => $this->company->name,
        ];
    }

    private function resolveUser(int $userId): ?Users
    {
        try {
            /** @var Users $user */
            $user = Users::getById($userId);
            // Tenant guard — throws when the user is not part of this app/company.
            UsersRepository::belongsToThisApp($user, $this->app, $this->company);

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * displayname is unique per app, so resolve by app then let resolveUser's tenant guard
     * confirm the teammate actually belongs to this company — an agent only describes users in
     * its own company, not app-global users or users from another company.
     */
    private function resolveByHandle(string $handle): ?Users
    {
        $handle = ltrim(trim($handle), '@');

        if ($handle === '') {
            return null;
        }

        $association = UsersAssociatedApps::query()
            ->where('apps_id', $this->app->getId())
            ->where('displayname', $handle)
            ->first();

        if ($association === null) {
            return null;
        }

        return $this->resolveUser((int) $association->users_id);
    }
}

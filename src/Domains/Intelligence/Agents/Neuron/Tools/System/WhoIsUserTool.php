<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Users\Models\Users;
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
            description: 'Find out who you are talking to (or look up another user in this company by id) — their name, email and company. '
                . 'Use it to ground your response in who the person is.',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $user_id = null): array
    {
        $user = $user_id !== null ? $this->resolveUser($user_id) : $this->currentUser;

        if ($user === null) {
            return [
                'status' => 'error',
                'message' => 'No user in scope. Pass a user_id of a user in this company.',
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
}

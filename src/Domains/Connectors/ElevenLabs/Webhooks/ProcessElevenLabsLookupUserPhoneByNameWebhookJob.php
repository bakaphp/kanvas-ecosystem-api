<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Override;

class ProcessElevenLabsLookupUserPhoneByNameWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';

        if ($name === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Name is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $users = Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.companies_id', $company->getId())
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->where(function (Builder $query) use ($name): void {
                $like = '%' . $name . '%';
                $query->where('users.firstname', 'like', $like)
                    ->orWhere('users.lastname', 'like', $like)
                    ->orWhere('users.displayname', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', users.firstname, users.lastname) like ?", [$like]);
            })
            ->groupBy('users.id')
            ->limit(10)
            ->get();

        if ($users->isEmpty()) {
            return [
                'message' => 'No users found',
                'name' => $name,
                'matches' => [],
            ];
        }

        return [
            'message' => 'Users found',
            'name' => $name,
            'matches' => $users->map(fn (Users $user): array => $this->formatUser($user))->all(),
        ];
    }

    protected function formatUser(Users $user): array
    {
        return [
            'id' => $user->getId(),
            'firstname' => (string) $user->firstname,
            'lastname' => (string) $user->lastname,
            'displayname' => (string) $user->displayname,
            'phone_number' => (string) ($user->phone_number ?? ''),
            'cell_phone_number' => (string) ($user->cell_phone_number ?? ''),
        ];
    }
}

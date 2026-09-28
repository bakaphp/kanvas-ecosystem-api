<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

#[WorkflowAction(
    name: 'ElevenLabs Look Up Staff Phone By Name',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one resolves a spoken name to a colleague\'s phone number, so the '
        . 'voice agent can transfer a caller who asked for someone in particular. Read-only, and scoped '
        . 'to this company\'s own staff.',
)]
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

        [$firstname, $lastname] = $this->splitName($name);

        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $users = Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.companies_id', $company->getId())
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->where(function (Builder $query) use ($firstname, $lastname): void {
                $query->where('users.firstname', 'like', '%' . $firstname . '%');

                if ($lastname !== '') {
                    $query->where('users.lastname', 'like', '%' . $lastname . '%');
                }
            })
            ->groupBy('users.id')
            ->limit(10)
            ->get();

        if ($users->isEmpty()) {
            return [
                'message' => 'No users found',
                'name' => $name,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'matches' => [],
            ];
        }

        return [
            'message' => 'Users found',
            'name' => $name,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'matches' => $users->map(fn (Users $user): array => $this->formatUser($user))->all(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        $firstname = trim($parts[0] ?? '');
        $lastname = trim($parts[1] ?? '');

        return [$firstname, $lastname];
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

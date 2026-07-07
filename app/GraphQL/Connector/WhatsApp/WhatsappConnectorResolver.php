<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\WhatsApp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\ConnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Services\SessionService;

class WhatsappConnectorResolver
{
    /**
     * @return array<string, mixed>
     */
    public function connect(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = (array) $request['input'];

        return new ConnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            user: $user,
            agentId: (int) $input['agent_id'],
            phoneNumber: (string) $input['phone_number'],
            sessionName: $input['session_name'] ?? null,
        )->execute();
    }

    public function status(mixed $root, array $request): string
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $session = new SessionService(
            $app,
            $company
        )->getSession((int) $request['session_id']);

        return (string) ($session['status'] ?? 'unknown');
    }
}

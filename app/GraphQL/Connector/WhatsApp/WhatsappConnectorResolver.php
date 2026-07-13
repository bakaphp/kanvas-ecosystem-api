<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\WhatsApp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\ConnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\DisconnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\RefreshWhatsAppQrCodeAction;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * WhatsApp connector GraphQL surface. Provider (WaSender) is intentionally hidden from the
 * schema — clients only ever see "WhatsApp". Company is derived from the authenticated user.
 */
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
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        return new ConnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            user: $user,
            agent: $agent,
            phoneNumber: (string) $input['phone_number'],
            sessionName: $input['session_name'] ?? null,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshQrCode(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        return new RefreshWhatsAppQrCodeAction(
            app: $app,
            company: $company,
            agent: $agent,
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        return new DisconnectWhatsAppSessionAction(
            app: $app,
            company: $company,
            agent: $agent,
            remove: (bool) ($request['remove'] ?? false),
        )->execute();
    }

    public function status(mixed $root, array $request): string
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $session = new SessionService($app, $company)->getSession((int) $request['session_id']);

        return (string) ($session['status'] ?? 'unknown');
    }
}

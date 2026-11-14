<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\WhatsApp;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\WaSender\Actions\ConnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\DisconnectWhatsAppSessionAction;
use Kanvas\Connectors\WaSender\Actions\RefreshWhatsAppQrCodeAction;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * WhatsApp connector GraphQL surface. Provider (WaSender) is intentionally hidden from the
 * schema — clients only ever see "WhatsApp". Company is derived from the authenticated user.
 */
class WhatsappConnectorResolver
{
    use ResolvesActingContext;

    /**
     * @return array<string, mixed>
     */
    public function connect(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = (array) $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $ctx->company, $ctx->app);

        return new ConnectWhatsAppSessionAction(
            app: $ctx->app,
            company: $ctx->company,
            user: $ctx->user,
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
        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $ctx->company, $ctx->app);

        return new RefreshWhatsAppQrCodeAction(
            app: $ctx->app,
            company: $ctx->company,
            agent: $agent,
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $ctx->company, $ctx->app);

        return new DisconnectWhatsAppSessionAction(
            app: $ctx->app,
            company: $ctx->company,
            agent: $agent,
            remove: (bool) ($request['remove'] ?? false),
        )->execute();
    }

    /**
     * Sessions are read with the app's account token, which reaches every company's: only one the
     * caller's company linked is answered for.
     */
    public function status(mixed $root, array $request): string
    {
        $ctx = $this->actingContext();
        $sessionId = (int) $request['session_id'];

        if (! WaSenderHandler::ownsSession($ctx->app, $ctx->company, $sessionId)) {
            throw new ValidationException('No WhatsApp session with that id on this company.');
        }

        $session = new SessionService($ctx->app, $ctx->company)->getSession($sessionId);

        return (string) ($session['status'] ?? 'unknown');
    }
}

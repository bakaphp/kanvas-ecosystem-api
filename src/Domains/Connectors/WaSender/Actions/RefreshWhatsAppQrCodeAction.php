<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

class RefreshWhatsAppQrCodeAction
{
    protected SessionService $sessionService;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Agent $agent,
        ?SessionService $sessionService = null,
    ) {
        $this->sessionService = $sessionService ?? new SessionService($app, $company);
    }

    /**
     * @return array{session_id: int, status: string, qr_code: string|null}
     */
    public function execute(): array
    {
        $sessionId = (int) $this->agent->get(ConnectionFieldEnum::SESSION_ID->value);
        if ($sessionId <= 0) {
            throw new ValidationException('No WhatsApp session for this agent — connect it first.');
        }

        // /connect regenerates the QR; fall back to the dedicated QR endpoint if it isn't inline.
        $connection = $this->sessionService->connectSession($sessionId, true);
        $qrCode = SessionService::extractQr($connection)
            ?? SessionService::extractQr($this->sessionService->getSessionQrCode($sessionId));

        return [
            'session_id' => $sessionId,
            'status' => (string) ($connection['status'] ?? 'need_scan'),
            'qr_code' => $qrCode,
        ];
    }
}

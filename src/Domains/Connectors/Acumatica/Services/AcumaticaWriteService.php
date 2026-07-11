<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Throwable;

/**
 * The single gated entry point for pushing an approved Kanvas document out to Acumatica.
 *
 * Kanvas-first protocol: agents/actions build and get documents approved inside Scribe, then this
 * service writes the approved record to Acumatica — nothing unvalidated ever reaches the ERP. Writes
 * are hard-gated behind the per-app ACUMATICA_WRITE_ENABLED flag (default off), so a
 * misconfigured/pull-only tenant can never accidentally mutate Acumatica.
 *
 * Each push runs in its own authenticated REST session (login → put → optional Release → logout).
 */
class AcumaticaWriteService
{
    private ?Client $client;

    public function __construct(
        protected AppInterface $app,
        ?Client $client = null,
    ) {
        $this->client = $client;
    }

    public function isWriteEnabled(): bool
    {
        return (bool) $this->app->get(ConfigurationEnum::ACUMATICA_WRITE_ENABLED->value);
    }

    public function assertWriteEnabled(): void
    {
        if (! $this->isWriteEnabled()) {
            throw new AcumaticaWriteException(
                'Acumatica write-back is disabled for this app — set ACUMATICA_WRITE_ENABLED to turn it on.'
            );
        }
    }

    /**
     * Create (or update) a contract-based REST entity and optionally Release it, in one session.
     *
     * @param string               $entity  the endpoint entity name (e.g. 'Bill', 'Payment')
     * @param array<string, mixed> $body    already `{value:}`-wrapped payload (use AcumaticaPayload::wrap)
     *
     * @return array<array-key, mixed> the persisted record — includes the `id` GUID + key fields
     */
    public function push(string $entity, array $body, bool $release = false): array
    {
        $this->assertWriteEnabled();

        $client = $this->client();

        try {
            $client->login();

            $record = $client->put($entity, $body);

            if ($release) {
                $id = AcumaticaPayload::recordId($record);

                if ($id !== null) {
                    $client->invokeAction($entity, 'Release', ['entity' => ['id' => $id]]);
                }
            }

            return $record;
        } catch (AcumaticaWriteException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AcumaticaWriteException::fromThrowable($e, "push {$entity}");
        } finally {
            $this->safeLogout();
        }
    }

    private function client(): Client
    {
        return $this->client ??= Client::getInstance($this->app);
    }

    private function safeLogout(): void
    {
        try {
            $this->client?->logout();
        } catch (Throwable) {
            // A failed logout must never mask the real write error.
        }
    }
}

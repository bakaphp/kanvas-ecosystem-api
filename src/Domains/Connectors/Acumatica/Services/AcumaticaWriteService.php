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
        protected ?string $companyOverride = null,
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
     * Create/update an entity, attach files, and optionally Release — one authenticated session.
     * Files attach BEFORE the Release so they land on the draft.
     *
     * `$findQuery` makes the push idempotent against retries: if a matching record already exists
     * (e.g. a prior attempt created it in Acumatica but crashed before the local id was stored), it
     * is adopted instead of created — preventing a duplicate document on retry. Runs in the same
     * session, so it costs no extra login.
     *
     * @param array<string, mixed>                                             $body  `{value:}`-wrapped (AcumaticaPayload::wrap)
     * @param array<int, array{name: string, content: string, type?: string}> $files
     * @param array<string, mixed>|null                                        $findQuery OData filter to adopt an existing record
     * @param string                                                            $releaseAction action name to invoke when $release is true (some entities use a non-generic name, e.g. Check -> ReleaseCheck)
     *
     * @return array<array-key, mixed> the persisted record (includes the `id` GUID)
     */
    public function push(
        string $entity,
        array $body,
        bool $release = false,
        array $files = [],
        ?array $findQuery = null,
        string $releaseAction = 'Release'
    ): array {
        $this->assertWriteEnabled();
        $this->keepRunningPastClientDisconnect();

        $client = $this->client();

        try {
            $client->login();

            if ($findQuery !== null) {
                $found = $client->get($entity, $findQuery);

                if (isset($found[0]) && is_array($found[0])) {
                    return $found[0];
                }
            }

            $record = $client->put($entity, $body);

            $this->attachFiles($client, $record, $files);

            if ($release) {
                $id = AcumaticaPayload::recordId($record);

                if ($id !== null) {
                    $client->invokeAction($entity, $releaseAction, ['entity' => ['id' => $id]]);
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

    /** Runs a callback (receiving the raw Client) against a single authenticated session, for multi-call write flows push() doesn't cover. */
    public function withSession(callable $callback): mixed
    {
        $this->assertWriteEnabled();
        $this->keepRunningPastClientDisconnect();

        $client = $this->client();

        try {
            $client->login();

            return $callback($client);
        } catch (AcumaticaWriteException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AcumaticaWriteException::fromThrowable($e, 'session');
        } finally {
            $this->safeLogout();
        }
    }

    /**
     * @param array<string, mixed> $query      OData query, e.g. ['$filter' => "VendorName eq 'X'", '$top' => 1]
     * @param array<string, mixed> $createBody `{value:}`-wrapped payload for the create branch
     *
     * @return array<array-key, mixed> the found or created record
     */
    public function findOrCreate(string $entity, array $query, array $createBody): array
    {
        $this->assertWriteEnabled();
        $this->keepRunningPastClientDisconnect();

        $client = $this->client();

        try {
            $client->login();

            $found = $client->get($entity, $query);

            if (isset($found[0]) && is_array($found[0])) {
                return $found[0];
            }

            return $client->put($entity, $createBody);
        } catch (AcumaticaWriteException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AcumaticaWriteException::fromThrowable($e, "findOrCreate {$entity}");
        } finally {
            $this->safeLogout();
        }
    }

    /**
     * Best-effort: a missing files link or a failed attachment is swallowed — the document is already
     * created, so an attachment must never abort the push.
     *
     * @param array<array-key, mixed>                                          $record
     * @param array<int, array{name: string, content: string, type?: string}> $files
     */
    private function attachFiles(Client $client, array $record, array $files): void
    {
        if ($files === []) {
            return;
        }

        $template = AcumaticaPayload::filesPutHref($record);

        if ($template === null) {
            return;
        }

        foreach ($files as $file) {
            try {
                $url = str_ireplace('{filename}', rawurlencode($file['name']), $template);
                $client->putFile($url, $file['content'], $file['type'] ?? 'application/octet-stream');
            } catch (Throwable) {
            }
        }
    }

    private function client(): Client
    {
        return $this->client ??= Client::getInstance($this->app, $this->companyOverride);
    }

    private function safeLogout(): void
    {
        try {
            $this->client?->logout();
        } catch (Throwable) {
            // A failed logout must never mask the real write error.
        }
    }

    /** A client disconnect mid-write would otherwise abort PHP before our finally-block logout runs, leaking the Acumatica session. */
    private function keepRunningPastClientDisconnect(): void
    {
        ignore_user_abort(true);
    }
}

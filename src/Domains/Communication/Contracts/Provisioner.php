<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Baka\Contracts\AppInterface;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

/**
 * What only the vendor's API can do for a connection: check the stored credentials, point the
 * account's inbound at Kanvas and read it back, and later take that registration away again —
 * only while it is still ours.
 */
interface Provisioner
{
    /**
     * Read-only, with what the integration stored. Never re-runs the handler's setup.
     *
     * @return string|null why the credentials cannot be used, or null when they can
     */
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string;

    /**
     * The jobs this provider's receivers ran before the one communication webhook. A receiver on one
     * of them, made by hand, is evidence of which app a legacy integration belongs to.
     *
     * @return list<class-string<ProcessWebhookJob>>
     */
    public function legacyReceiverJobs(): array;

    /**
     * How this provider's conversations are told apart, so the tenant's one auto-reply rule answers
     * them, and the responder it answered with before the conversation responder — a tenant that
     * already has a rule on that one keeps it and is not given a second. Null when an agent never
     * answers this provider's inbound.
     *
     * @return array{channel_slug_pattern: string, legacy_responder: class-string|null}|null
     */
    public function autoReply(): ?array;

    /**
     * Point the account's inbound at $inboundUrl and read it back. A registration someone else
     * made is never replaced.
     *
     * @throws SetupBlockedException when the vendor holds a registration that is not ours
     */
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration;

    /**
     * @return string|null a prerequisite only the customer can satisfy, or null when none is missing
     */
    public function missingPrerequisite(Connection $connection): ?string;

    /**
     * Remove what the task names, if the vendor still holds the value we set.
     */
    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum;

    /**
     * Unregister a shared resource once nothing depends on it, under the same ownership check.
     */
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum;

    /**
     * For an integration saved before accounts were selected in the integration flow: the accounts
     * its company was already sending from, in the shape the handler persists them.
     *
     * @return array<string, mixed> config to merge onto the row, or [] when nothing can be derived
     */
    public function legacySelection(IntegrationsCompany $integrationCompany): array;
}

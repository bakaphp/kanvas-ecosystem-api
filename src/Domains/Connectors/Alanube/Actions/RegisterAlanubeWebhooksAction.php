<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeWebhookRegistration;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Enums\WebhookEventEnum;
use Kanvas\Connectors\Alanube\Services\AlanubeWebhookSecretService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Auto-configures the company's four Alanube webhooks (plan §6.3). Connect, reconnect, onboarding and the
 * readiness repair all run this same action, so it must be idempotent:
 * - receivers are found by job before one is created;
 * - Alanube is only PATCHed when what it holds differs from what we expect (URL, active, secret header name);
 * - the result is verified with a GET, which is the readiness line.
 *
 * All four webhooks are always sent together. Alanube's PATCH is documented as a partial update ("only the
 * information sent is updated"), but sending the whole block is correct whether it merges or replaces.
 *
 * Receivers come first: Alanube POSTs a `{"message": "Test message"}` to every active webhook before it saves
 * the configuration (HTTP 400 AP1023 when that fails), so the URL and secret must already answer.
 */
class RegisterAlanubeWebhooksAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly bool $rotateSecret = false,
    ) {
    }

    public function execute(): AlanubeWebhookRegistration
    {
        $secrets = new AlanubeWebhookSecretService($this->company);
        $secrets->dropExpiredPrevious();

        $urls = $this->receiverUrls();

        $unreachable = $this->unreachableHostReason();
        if ($unreachable !== null) {
            return new AlanubeWebhookRegistration(
                outcome: AlanubeWebhookRegistration::SKIPPED,
                verified: false,
                receiverUrls: $urls,
                reason: $unreachable,
            );
        }

        $secret = $this->rotateSecret ? $secrets->rotate() : $secrets->currentOrCreate();
        $client = new Client($this->app, $this->company);

        $mismatches = $this->mismatches($this->held($client), $urls);
        $outcome = AlanubeWebhookRegistration::UNCHANGED;

        if ($mismatches !== [] || $this->rotateSecret) {
            $client->patch($client->companyPath(), ['webhooks' => $this->block($urls, $secret)]);
            $outcome = AlanubeWebhookRegistration::UPDATED;
            $mismatches = $this->mismatches($this->held($client), $urls);
        }

        return new AlanubeWebhookRegistration(
            outcome: $outcome,
            verified: $mismatches === [],
            receiverUrls: $urls,
            mismatches: $mismatches,
            reason: $mismatches === [] ? null : 'Alanube does not hold the expected webhooks: ' . implode('; ', $mismatches),
        );
    }

    /**
     * The `webhooks` block for `POST /company`, so a company onboarded by API is born with its webhooks. Empty
     * when Alanube cannot reach us, since it would refuse the whole company over an unreachable webhook.
     *
     * @return array<string, mixed>
     */
    public function webhooksBlock(): array
    {
        if ($this->unreachableHostReason() !== null) {
            return [];
        }

        return $this->block($this->receiverUrls(), new AlanubeWebhookSecretService($this->company)->currentOrCreate());
    }

    /**
     * @param array<string, string> $urls
     * @return array<string, mixed>
     */
    private function block(array $urls, string $secret): array
    {
        $block = [];

        foreach (WebhookEventEnum::cases() as $event) {
            $block[$event->group()][$event->key()] = [
                'status' => 'active',
                'url' => $urls[$event->value],
                'headers' => [ConfigurationEnum::WEBHOOK_SECRET_HEADER => $secret],
            ];
        }

        return $block;
    }

    /**
     * @return array<string, mixed>
     */
    private function held(Client $client): array
    {
        $company = Client::companyRecord($client->get($client->companyPath()) ?? []);

        return is_array($company['webhooks'] ?? null) ? $company['webhooks'] : [];
    }

    /**
     * Header names only: whether Alanube echoes header values back in clear is not documented, and comparing
     * a masked value would PATCH on every run. A secret change goes through `rotateSecret`, which always PATCHes.
     *
     * @param array<string, mixed> $held
     * @param array<string, string> $urls
     * @return string[]
     */
    private function mismatches(array $held, array $urls): array
    {
        $mismatches = [];

        foreach (WebhookEventEnum::cases() as $event) {
            $webhook = $held[$event->group()][$event->key()] ?? null;

            if (! is_array($webhook)) {
                $mismatches[] = "{$event->value} missing";

                continue;
            }

            if (($webhook['url'] ?? null) !== $urls[$event->value]) {
                $mismatches[] = "{$event->value} points elsewhere";
            }

            if (($webhook['status'] ?? 'active') !== 'active') {
                $mismatches[] = "{$event->value} inactive";
            }

            $headers = array_change_key_case(is_array($webhook['headers'] ?? null) ? $webhook['headers'] : []);
            if (! array_key_exists(ConfigurationEnum::WEBHOOK_SECRET_HEADER, $headers)) {
                $mismatches[] = "{$event->value} has no secret header";
            }
        }

        return $mismatches;
    }

    /**
     * @return array<string, string> keyed by WebhookEventEnum value
     */
    private function receiverUrls(): array
    {
        return array_map(fn (ReceiverWebhook $receiver): string => $receiver->getUrl(), $this->ensureReceivers());
    }

    /**
     * @return array<string, ReceiverWebhook> keyed by WebhookEventEnum value
     */
    private function ensureReceivers(): array
    {
        $receivers = [];

        foreach (WebhookEventEnum::cases() as $event) {
            $receivers[$event->value] = ReceiverWebhook::query()
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->forJob($event->jobClass())
                ->first()
                ?? $this->createReceiver($event);
        }

        return $receivers;
    }

    private function createReceiver(WebhookEventEnum $event): ReceiverWebhook
    {
        $action = WorkflowAction::query()->where('model_name', $event->jobClass())->first()
            ?? throw new ValidationException(
                'The Alanube webhook actions are not registered. Run `kanvas:workflow-sync-actions` (it runs on '
                . 'deploy) so the jobs are discoverable, then connect Alanube again.'
            );

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->app,
                company: $this->company,
                user: $this->company->user,
                action: $action,
                name: $event->receiverName(),
                description: "Alanube {$event->value} webhook.",
                configuration: ['alanube_event' => $event->value],
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    /**
     * Alanube refuses localhost, private networks and internal hosts outright, so a dev box skips registration
     * and relies on polling instead of failing setup.
     */
    private function unreachableHostReason(): ?string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|test|internal)$/', $host) === 1) {
            return "app.url host '{$host}' is not reachable from Alanube; webhooks skipped, status is polled.";
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return "app.url host '{$host}' is a private address; webhooks skipped, status is polled.";
        }

        return null;
    }
}

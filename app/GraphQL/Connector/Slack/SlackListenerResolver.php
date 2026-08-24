<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Slack;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Actions\ConnectSlackListenerAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackListenerAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackListenerManifestAction;
use Kanvas\Connectors\Slack\Jobs\JoinAllPublicChannelsJob;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;
use Kanvas\Connectors\Slack\Services\SlackListenerStatusService;

class SlackListenerResolver
{
    use ResolvesActingContext;

    /**
     * @return array<string, mixed>
     */
    public function manifest(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Companies $company */
        $company = $ctx->company;

        return new GenerateSlackListenerManifestAction($ctx->app, $company)->execute();
    }

    /**
     * @return array<string, mixed>|null null when the company has never connected a listener
     */
    public function status(mixed $root, array $request): ?array
    {
        $ctx = $this->actingContext();

        return new SlackListenerStatusService()->forCompany($ctx->app, $ctx->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = (array) $request['input'];

        /** @var Companies $company */
        $company = $ctx->company;

        return new ConnectSlackListenerAction(
            app: $ctx->app,
            company: $company,
            botToken: (string) $input['bot_token'],
            signingSecret: (string) $input['signing_secret'],
            channelDenyList: array_values(array_map('strval', (array) ($input['channel_deny_list'] ?? []))),
            ingestFiles: (bool) ($input['ingest_files'] ?? false),
        )->execute();
    }

    public function disconnect(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        return new DisconnectSlackListenerAction($ctx->app, $ctx->company)->execute();
    }

    public function resync(mixed $root, array $request): bool
    {
        $ctx = $this->actingContext();

        $receiver = new SlackListenerReceiverService()->findForCompany($ctx->app, $ctx->company);

        if ($receiver === null || ! $receiver->is_active) {
            return false;
        }

        JoinAllPublicChannelsJob::dispatch($receiver->app, $receiver);

        return true;
    }
}

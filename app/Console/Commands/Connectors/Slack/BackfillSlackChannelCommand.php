<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Slack;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Jobs\BackfillSlackChannelHistoryJob;
use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * The automatic pull on invite stops at its window. A tenant that wants a room's whole year runs
 * this; it goes through the same job, so it is as safe to repeat as the automatic one.
 */
class BackfillSlackChannelCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:slack:backfill-channel
        {app : App id}
        {agent : Agent id whose Slack app is a member of the room}
        {channel : Slack channel id (C… or G…)}
        {--days=' . BackfillSlackChannelHistoryJob::DEFAULT_DAYS . ' : How far back to pull}';

    protected $description = 'Pull a Slack room\'s history into the agent\'s channel, back to --days ago.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app'));
        $this->overwriteAppService($app);

        $agent = Agent::getById((int) $this->argument('agent'), $app);
        $receiver = new SlackReceiverService()->forAgent($agent);
        $slackChannelId = (string) $this->argument('channel');
        $days = max(1, (int) $this->option('days'));

        if (! BackfillSlackChannelHistoryJob::dispatchIfIdle($receiver, $slackChannelId, $days)) {
            $this->warn('A backfill for this room is already running.');

            return self::FAILURE;
        }

        $this->info(sprintf('Queued a %d-day backfill of %s for agent %d.', $days, $slackChannelId, $agent->getId()));

        return self::SUCCESS;
    }
}

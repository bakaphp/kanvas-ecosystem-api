<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\SocialEngagementAgent;
use Kanvas\Users\Models\Users;

class SocialAgentEngagerCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:social-agent-engager {app_id} {--force}';
    protected $description = 'Execute social engagement automation using AI agents';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $currentHour = (int) date('G');
        $currentDate = date('Y-m-d');

        // Get agents configuration from app settings
        $agents = $app->get('social-engagement-agents');

        if (! is_array($agents) || empty($agents)) {
            $this->error('No social engagement agents found for app ' . $app->name);
            $this->info('Configure agents in app settings with key: social-engagement-agents');
            $this->info('Example format:');
            $this->info('[');
            $this->info('  {');
            $this->info('    "email": "agent@example.com",');
            $this->info('    "password": "password123",');
            $this->info('    "activeHour": 9,');
            $this->info('    "bio": "I am interested in technology and AI",');
            $this->info('    "pages_per_session": 3');
            $this->info('  }');
            $this->info(']');

            return;
        }

        // Find agents for current hour
        $currentAgent = null;
        shuffle($agents);

        foreach ($agents as $user) {
            if (isset($user['activeHour']) && $user['activeHour'] === $currentHour) {
                $currentAgent = $user;

                break;
            }
        }

        if ($currentAgent === null) {
            $this->info('No agent assigned to hour ' . $currentHour . '. Exiting.');

            return;
        }

        // Check if already executed today (unless forced)
        $redisKey = "social_agent_execution:{$currentAgent['email']}:{$currentDate}:{$currentHour}";

        if (! $this->option('force') && Redis::exists($redisKey)) {
            $this->info('Agent ' . $currentAgent['email'] . ' has already executed for hour ' . $currentHour . ' today. Use --force to override.');

            return;
        }

        // Set execution flag with 24-hour expiry
        Redis::setex($redisKey, 86400, 'executed');

        $this->info('Starting social engagement for agent: ' . $currentAgent['email'] . ' at hour ' . $currentHour);

        try {
            $this->runAgent($app, $currentAgent);
        } catch (Exception $e) {
            report($e);
            $this->error('Agent execution failed: ' . $e->getMessage());
        }
    }

    protected function runAgent(Apps $app, array $agentConfig): void
    {
        // Find or create agent model
        $agent = Agent::where('apps_id', $app->getId())
            ->where('agent_type_id', 3) // Assuming 3 is the ID for social engagement agent
            ->first();

        // Find user/person for this agent
        /*   $person = People::whereHas('emails', function ($query) use ($agentConfig) {
              $query->where('value', $agentConfig['email']);
          })->where('companies_id', $app->companies_id)->first(); */

        $user = Users::where('email', $agentConfig['email'])
            ->first();

        if (! $user) {
            $this->error('User not found for email: ' . $agentConfig['email']);

            return;
        }

        // Initialize social engagement agent
        $socialAgent = new SocialEngagementAgent();
        $socialAgent->setConfiguration($agent, $user);

        $this->info('Processing social engagement...');

        // Process engagement using the agent
        $result = $socialAgent->processEngagement($agentConfig);

        if (! $result['success']) {
            $this->error('Engagement processing failed: ' . ($result['error'] ?? 'Unknown error'));

            return;
        }

        // Log completion summary
        $this->info('');
        $this->info('Agent ' . $agentConfig['email'] . ' completed social engagement:');
        $this->info("- Total processed: {$result['total_processed']}");
        $this->info("- Total skipped: {$result['total_skipped']}");
        $this->info("- Total engaged: {$result['total_engaged']}");
        $this->info("- Views: {$result['views']}");
        $this->info("- Clicks: {$result['clicks']}");
        $this->info("- Likes: {$result['likes']}");
        $this->info("- Errors: {$result['errors']}");
        $this->info("- Pages processed: {$result['pages_processed']}");
        $this->info("- Efficiency: {$result['efficiency']}");

        // Store summary in Redis
        $summaryKey = "social_agent_summary:{$agentConfig['email']}:" . date('Y-m-d-H');
        $summaryData = array_merge($result, [
            'email' => $agentConfig['email'],
            'completed_at' => now()->toISOString(),
        ]);
        Redis::setex($summaryKey, 604800, json_encode($summaryData)); // 7 days
    }
}

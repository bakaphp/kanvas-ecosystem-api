<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Types\SocialCreatorAgent;
use Kanvas\Users\Models\Users;

class SocialCreatorCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:social-agent-creator {app_id} {--force}';
    protected $description = 'Execute social content creation automation using AI agents';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $currentHour = (int) date('G');
        $currentDate = date('Y-m-d');

        // Get agents configuration from app settings
        $agents = $app->get('social-creator-agents');

        if (! is_array($agents) || empty($agents)) {
            $this->error('No social creator agents found for app ' . $app->name);
            $this->info('Configure agents in app settings with key: social-creator-agents');
            $this->info('Example format:');
            $this->info('[');
            $this->info('  {');
            $this->info('    "email": "creator@example.com",');
            $this->info('    "password": "password123",');
            $this->info('    "activeHour": 10,');
            $this->info('    "bio": "I create engaging AI prompts and productivity content",');
            $this->info('    "posts_per_session": 2,');
            $this->info('    "content_type": "prompt",');
            $this->info('    "target_audience": "developers"');
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
            $this->info('No creator agent assigned to hour ' . $currentHour . '. Exiting.');

            return;
        }

        // Check if already executed today (unless forced)
        $redisKey = "social_creator_execution:{$currentAgent['email']}:{$currentDate}:{$currentHour}";

        if (! $this->option('force') && Redis::exists($redisKey)) {
            $this->info('Creator agent ' . $currentAgent['email'] . ' has already executed for hour ' . $currentHour . ' today. Use --force to override.');

            return;
        }

        // Set execution flag with 24-hour expiry
        Redis::setex($redisKey, 86400, 'executed');

        $this->info('Starting content creation for agent: ' . $currentAgent['email'] . ' at hour ' . $currentHour);

        try {
            $this->runCreatorAgent($app, $currentAgent);
        } catch (Exception $e) {
            report($e);
            $this->error('Creator agent execution failed: ' . $e->getMessage());
        }
    }

    protected function runCreatorAgent(Apps $app, array $agentConfig): void
    {
        // Find or create agent model
        $agent = Agent::where('apps_id', $app->getId())
            ->where('agent_type_id', 4) // Assuming 4 is the ID for social creator agent
            ->first();

        // Find user for this agent
        $user = Users::where('email', $agentConfig['email'])
                ->first();

        if (! $user) {
            $this->error('User not found for email: ' . $agentConfig['email']);

            return;
        }

        // Initialize social creator agent
        $socialCreator = new SocialCreatorAgent();
        $socialCreator->setConfiguration($agent, $user);

        $this->info('Processing content creation...');

        // Process content creation using the agent
        $result = $socialCreator->processContentCreation($agentConfig);

        if (! $result['success']) {
            $this->error('Content creation failed: ' . ($result['error'] ?? 'Unknown error'));

            return;
        }

        // Log completion summary
        $this->info('');
        $this->info('Creator agent ' . $agentConfig['email'] . ' completed content creation:');
        $this->info('Creator Bio: ' . ($agentConfig['bio'] ?? 'No bio provided'));
        $this->info("- Total created: {$result['total_created']}");
        $this->info("- Total failed: {$result['total_failed']}");
        $this->info("- Success rate: {$result['success_rate']}%");

        if (! empty($result['created_posts'])) {
            $this->info('- Created posts:');
            foreach ($result['created_posts'] as $post) {
                $this->info("  * ID {$post['message_id']}: {$post['title']}");
            }
        }

        // Store summary in Redis
        $summaryKey = "social_creator_summary:{$agentConfig['email']}:" . date('Y-m-d-H');
        $summaryData = array_merge($result, [
            'email' => $agentConfig['email'],
            'completed_at' => now()->toISOString(),
            'agent_config' => [
                'bio' => $agentConfig['bio'] ?? '',
                'posts_per_session' => $agentConfig['posts_per_session'] ?? 1,
                'content_type' => $agentConfig['content_type'] ?? 'prompt',
                'target_audience' => $agentConfig['target_audience'] ?? null,
            ],
        ]);
        Redis::setex($summaryKey, 604800, json_encode($summaryData)); // 7 days

        // Optional: Store individual post data for analytics
        if (! empty($result['created_posts'])) {
            foreach ($result['created_posts'] as $post) {
                $postKey = "social_creator_post:{$agentConfig['email']}:{$post['message_id']}";
                $postData = [
                    'message_id' => $post['message_id'],
                    'title' => $post['title'],
                    'created_at' => $post['created_at'],
                    'agent_email' => $agentConfig['email'],
                    'session_date' => date('Y-m-d-H'),
                ];
                Redis::setex($postKey, 2592000, json_encode($postData)); // 30 days
            }
        }
    }
}

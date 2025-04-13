<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

class PromptCreatorAgentCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:prompt-agent-creator {app_id}';
    protected $description = 'Generate and post viral AI prompts using creator agents';
    protected ?string $url = null;
    protected ?string $appId = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->url = 'https://graphapi.kanvas.dev/graphql'; //$app->get('graphql-url');
        $this->appId = $app->key;

        // Get the current hour and date
        $currentHour = (int) date('G');
        $currentDate = date('Y-m-d');

        $users = $app->get('prompt-creator-agents');

        if (! is_array($users) || empty($users)) {
            $this->error('No creator agents found for app ' . $app->name);

            return;
        }

        // Find the agent assigned to the current hour
        $currentAgent = null;
        foreach ($users as $user) {
            if (isset($user['activeHour']) && $user['activeHour'] === $currentHour) {
                $currentAgent = $user;

                break;
            }
        }

        // If no agent is assigned to this hour, exit
        if ($currentAgent === null) {
            $this->info('No creator agent assigned to hour ' . $currentHour . '. Exiting.');

            return;
        }

        // Create a unique Redis key for this agent and hour
        $redisKey = "creator_agent_execution:{$currentAgent['email']}:{$currentDate}:{$currentHour}";

        // Check if this agent has already been executed in this hour
        if (Redis::exists($redisKey)) {
            //$this->info('Creator agent ' . $currentAgent['email'] . ' has already executed for hour ' . $currentHour . ' today. Exiting.');

            //return;
        }

        // Set the Redis key with an expiry of 24 hours (to clean up old keys)
        Redis::setex($redisKey, 86400, 'executed');

        $this->info('Starting creator agent for hour ' . $currentHour . ': ' . $currentAgent['email']);

        $agentPersonality = $currentAgent['bio'];
        $token = $this->login($currentAgent['email'], $currentAgent['password']);

        $this->info('Creator agent logged in: ' . $currentAgent['email']);

        // Generate a prompt using Gemini
        $prompt = $this->generateViralPrompt($agentPersonality);

        print_r($prompt);
        die();

        if ($prompt) {
            // Post the generated prompt as a message
            $messageId = $this->postMessage($token, $prompt['title'], $prompt['prompt'], $currentAgent);

            if ($messageId) {
                $this->info('Successfully posted prompt with message ID: ' . $messageId);
            } else {
                $this->error('Failed to post the prompt');
            }
        } else {
            $this->error('Failed to generate a viral prompt');
        }

        $this->info('Creator agent ' . $currentAgent['email'] . ' completed work for hour ' . $currentHour);
    }

    /**
     * Generate a viral prompt using AI
     *
     * @param string $agentPersonality The agent's personality/bio
     * @return array|null The generated prompt data or null if failed
     */
    protected function generateViralPrompt(string $agentPersonality): ?array
    {
        $promptEngineering = <<<PROMPT
**Role**:  
You are a world-class prompt engineer specializing in creating viral, high-engagement AI prompts. Your prompts are shared widely because they:  
1. **Solve urgent problems** with razor-sharp specificity.  
2. **Leverage trends** (tech, culture, seasonal events).  
3. **Elicit "wow" outputs** (surprising, emotional, or hyper-useful).  
4. **Encourage sharing** via customization hooks.  

### **Daily Task**  
Generate **1 viral-worthy prompt** based on the creator's personality described below:

Creator Bio:
"$agentPersonality"

#### **Step 1: Trend Injection**  
- Consider these high-engagement categories:
  - Career/Professional Development
  - Productivity Tools
  - Personal Growth/Self-Improvement
  - Education/Homework
  - Life Advice/Mental Health

#### **Step 2: Craft the Prompt**  
**A. Title Formula (Pick One)**  
- **"How to [X] Like a [Y] in [Time]"** *(e.g., "Negotiate Like a Shark Tank Investor in 5 Mins")*  
- **"The [Adjective] [Metaphor] for [Problem]"** *(e.g., "The 'Silent Killer' Prompt for Procrastination")*  
- **"Never [Pain Point] Again: [Solution]"** *(e.g., "Never Write a Boring Email Again: The 3-Line Hook Method")*  
**Titles MUST be 3-7 words in length**

**B. Prompt Structure**  
1. **Role**: "You are a [authority figure, e.g., 'NYT bestselling author']."  
2. **Goal**: "Generate [specific output]."  
3. **Constraints**: "Use [framework/tone/length]."  
4. **CTA**: Include a clear section for user input.

#### **Step 3: Quality Check**  
- **Surprise Test**: Would this output make someone screenshot it?  
- **Action Test**: Can it be used immediately?  
- **Share Trigger**: Does it invite customization?

### **Final Output Format**  
Return ONLY a **true JSON object**, avoiding markdown:
```json  
{  
  "title": "The '[Hook]' Prompt: [Benefit]",  
  "prompt": "[Structured prompt with Role, Goal, Constraints, CTA]",  
  "target_LLM": "GPT-4o/Claude/Mixtral"  
}  
```
PROMPT;

        try {
            $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt($promptEngineering)
                ->generate();

            $responseText = str_replace(['```', 'json'], '', $response->text);

            if (! Str::isJson($responseText)) {
                $this->error('Invalid response from Prism: ' . $responseText);

                return null;
            }

            $promptData = json_decode($responseText, true);

            if (! isset($promptData['title']) || ! isset($promptData['prompt'])) {
                $this->error('Missing required fields in prompt data');

                return null;
            }

            return $promptData;
        } catch (\Exception $e) {
            $this->error('Exception generating viral prompt: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Post a message with the generated prompt
     *
     * @param string $token Authentication token
     * @param string $title The title of the prompt
     * @param string $content The content of the prompt
     * @param array $agent The agent data
     * @return int|null The message ID if successful, null otherwise
     */
    protected function postMessage(string $token, string $title, string $content, array $agent): ?int
    {
        $mutation = <<<GQL
mutation createMessage(\$message: MessageInput!) {
  createMessage(message: \$message) {
    id
  }
}
GQL;

        $messageData = [
            'title' => $title,
            'body' => $content,
            'is_published' => true,
        ];

        try {
            $response = $this->getClient()->post(
                $this->url,
                [
                    'headers' => $this->getHeaders([
                        'Authorization' => $token,
                    ]),
                    'json' => [
                        'query' => $mutation,
                        'variables' => [
                            'message' => $messageData,
                        ],
                    ],
                ]
            );

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                $this->error('GraphQL Error: ' . json_encode($result['errors']));

                return null;
            }

            return (int) $result['data']['createMessage']['id'] ?? null;
        } catch (\Exception $e) {
            $this->error('Exception posting message: ' . $e->getMessage());

            return null;
        }
    }

    protected function getClient(): Client
    {
        return new Client([
            'verify' => false,
        ]);
    }

    protected function getHeaders(array $additional = []): array
    {
        $appUuid = $this->appId;
        $branchUid = '';

        return array_merge([
            'X-Kanvas-App' => $appUuid,
            'X-Kanvas-Location' => $branchUid,
        ], $additional);
    }

    protected function login(string $email, string $password): string
    {
        $login = <<<GQL
mutation login(\$data: LoginInput!) {
  login(data: \$data) {
    id
    token
    refresh_token
    token_expires
    refresh_token_expires
    time
    timezone
  }
}
GQL;

        $getToken = $this->getClient()->post(
            $this->url,
            [
                'headers' => $this->getHeaders(),
                'json' => [
                    'query' => $login,
                    'variables' => [
                        'data' => [
                            'email' => $email,
                            'password' => $password,
                        ],
                    ],
                ],
            ]
        );

        $loginResponse = json_decode($getToken->getBody()->getContents(), true);

        return 'Bearer ' . $loginResponse['data']['login']['token'];
    }
}

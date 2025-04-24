<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
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
    protected ?Apps $app = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->app = $app;
        $this->overwriteAppService($app);

        $this->url = $app->get('graphql-url');
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

        $nugget = $this->generateNugget($prompt['prompt']);

        // Function to return the formatted message array

        $model = $this->getRandomModel();

        $message = [
            'title' => $prompt['title'],
            'prompt' => $prompt['prompt'],
            'is_assistant' => false,
            'ai_model' => $model,
            'ai_nugged' => [
                'description' => $nugget['description'] ?? '',
                'title' => $prompt['title'],
                'ai_model' => $model,
                'nugget' => $nugget['nugget'],
                'id' => 2053,
                'type' => 'text-format',
                'created_at' => time() * 1000, // Current timestamp in milliseconds
                'updated_at' => time() * 1000,  // Current timestamp in milliseconds
            ],
            'type' => 'text-format',
        ];

        $recipients = $app->get('test-creator-agent-email');
        if (empty($recipients)) {
            $this->error('No email address found for test creator agent. Exiting.');

            return;
        }
        $subject = 'Test creator agent output - ' . $currentAgent['email'];
        Mail::raw(json_encode($message, JSON_PRETTY_PRINT), function ($message) use ($recipients, $subject) {
            foreach ($recipients as $recipient) {
                $message->to($recipient);
            }
            $message->subject($subject);

            // Optional: Add CC, BCC, or other properties
            // $message->cc('cc@example.com');
            // $message->bcc('bcc@example.com');
            // $message->from('sender@example.com', 'Sender Name');
        });

        /*  if ($prompt) {
             // Post the generated prompt as a message
             $messageId = $this->postMessage($token, $message, 'prompt', true);

             if ($messageId) {
                 $this->info('Successfully posted prompt with message ID: ' . $messageId);
             } else {
                 $this->error('Failed to post the prompt');
             }
         } else {
             $this->error('Failed to generate a viral prompt');
         } */

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
    Role: You are a world-class prompt engineer specializing in creating viral, high-engagement, ONE-SHOT AI prompts. Your prompts are self-contained and require no follow-up. Your prompts are shared widely because they:
    1. Solve urgent problems with razor-sharp specificity.
    2. Leverage emerging trends (tech, culture, seasonal events) within relevant categories.
    3. Elicit "stop the scroll" outputs (surprising, emotional, hyper-useful, or uniquely insightful).
    4. Encourage sharing via clear customization hooks.
    
    ### Daily Task
    Generate 1 self-contained, viral-worthy prompt based on the creator's personality described below:
    Creator Bio: "$agentPersonality"
    
    #### Step 1: Trend Injection
    - Consider these high-engagement categories and look for emerging trends within them:
        - Career/Professional Development (e.g., AI upskilling, remote work strategies)
        - Productivity Tools (e.g., AI assistants for specific tasks)
        - Personal Growth/Self-Improvement (e.g., building resilience in the digital age)
        - Education/Homework (e.g., AI for personalized learning)
        - Life Advice/Mental Health (e.g., managing digital overload)
    - Aim to combine trends from different categories in novel ways.
    
    #### Step 2: Craft the Prompt
    A. Title Formula (Pick One - prioritize positive framing and action):
        - "How to [Action Verb] [Benefit] Like a [Relatable Figure] in [Short Timeframe]"
        - "The [Intriguing Adjective] [Compelling Metaphor] for [Specific Problem]"
        - "[Benefit] in [Timeframe]: The [Adjective] Method for [Target Audience]"
        - Consider starting words like: Unlock, Discover, Master, Secret to, Effortlessly, Quickly. Titles MUST be 3-7 words.
    
    B. Prompt Structure
    1. Role: "You are a [highly credible authority figure relevant to the topic]."
    2. Goal: "Generate [very specific and actionable output]."
    3. Constraints: "Use a [specific framework/tone - e.g., concise, empathetic, step-by-step]/Keep it under [word/character limit]." (Provide short examples if helpful)
    4. CTA: "To make this your own, [instruction for customization - e.g., 'replace [X] with your specific situation,' 'share your results using #YourHashtag']."
    5. New lines must be separated with \n
    
    #### Step 3: Quality Check
    - Stop the Scroll Test: Would this output immediately grab attention and elicit a strong reaction?
    - Action Test: Can a user immediately understand and act upon the prompt?
    - Share Trigger: Does it clearly invite and facilitate customization and sharing?
    - Uniqueness Check: Does this prompt offer a fresh angle or novel application?
    
    ### Final Output Format
    Return ONLY a true JSON object, avoiding markdown:
    ```json
    {
      "title": "The '[Compelling Hook]' Prompt: [Key Benefit]",
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
        } catch (Exception $e) {
            $this->error('Exception generating viral prompt: ' . $e->getMessage());

            return null;
        }
    }

    protected function generateNugget(string $prompt): ?array
    {
        $nuggetGenerator = <<<ADVANCEPROMPT
    # Atomic Execution Engine
    You are a single-response AI that transforms prompts into complete, viral-ready outputs. Every response must:
    1. Generate a complete, self-contained response to the prompt
    2. Begin with a clear, descriptive title using the "# Title" format
    3. Provide comprehensive content that fully addresses the prompt
    4. Do NOT include phrases like "let me know if you need more" or "is there anything else"
    5. Do NOT frame this as the beginning of a conversation
    6. Maintain a length between 300-2000 characters (not including title)
    7. New lines must be separated with \n
    8. Replace any variables or placeholders with realistic examples
    
    # TONE AND STYLE:
    
    - Match the tone requested in the prompt (professional, creative, casual, etc.)
    - Organize information logically with appropriate structure
    - Include specific, actionable information rather than generalities
    - Ensure the content is engaging and valuable as a standalone piece
    
    # PROHIBITED ELEMENTS:
    
    - Conversational openings or closings
    - Questions directed at the user
    - References to follow-up interactions
    - Apologies or disclaimers about AI limitations
    - Excessive wordiness or padding
    
    # Execution Protocol:
    1. Parse prompt for core intent and style
    2. Generate title as "# [Unexpected Twist] [Core Topic]"
    3. Create output with:
       - Header hook (emoji + bold claim)
       - 3-5 key insights (bullet points)
       - 1 actionable template/code snippet
       - Customization reminder
    4. Validate no follow-up needed

    This is the prompt to execute: $prompt
    
    Output Requirements:
    {
        "title": "[Clear, Crisp Title Under 70 chars]",
        "nugget": "[Hook]\n[3 Knowledge Nuggets]\n[1 Template]\n[CTA]",
        "engagement_hook": "[Question that sparks comments]",
        "completeness_score": 1-10
    }
ADVANCEPROMPT;

        try {
            $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt($nuggetGenerator)
                ->asText();

            $responseText = str_replace(['```', 'json'], '', $response->text);

            if (! Str::isJson($responseText)) {
                $this->error('Invalid response from Prism: ' . $responseText);

                return null;
            }

            $promptData = json_decode($responseText, true);

            if (! isset($promptData['title']) || ! isset($promptData['nugget'])) {
                $this->error('Missing required fields in prompt data');

                return null;
            }

            return $promptData;
        } catch (Exception $e) {
            $this->error('Exception generating viral prompt: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Get a random AI model from the available models
     *
     * @param bool $freeOnly Whether to only return free models (price = 0 and is_locked = false)
     * @return array The randomly selected model information with provider key
     */
    public function getRandomModel($freeOnly = true)
    {
        $models = $this->app->get('llm_list_categorization_prod');
        $allModelValues = [];

        // Extract all model values into a flat array
        foreach ($models as $category) {
            foreach ($category['value'] as $provider) {
                foreach ($provider['value'] as $model) {
                    // If freeOnly is true, only include free models
                    if (! $freeOnly || ($model['payment']['price'] == 0 && ! $model['payment']['is_locked'])) {
                        // Store model with provider information
                        $allModelValues[] = [
                            'key' => $provider['key'],
                            'value' => $model['model'],
                            'name' => $model['name'],
                            'payment' => $model['payment'],
                            'icon' => $model['icon'],
                            'isDefault' => $model['isDefault'],
                            'isNew' => $model['isNew'],
                        ];
                    }
                }
            }
        }

        // If no models match the criteria, return null
        if (empty($allModelValues)) {
            return null;
        }

        // Select a random model
        $randomIndex = array_rand($allModelValues);

        return $allModelValues[$randomIndex];
    }

    // Example usage:
    // $randomModel = getRandomModel();
    // echo "Selected model: " . $randomModel['name'] . " (" . $randomModel['provider'] . ")";

    /**
     * Post a message with the generated prompt
     *
     * @param string $token Authentication token
     * @param string $title The title of the prompt
     * @param string $content The content of the prompt
     * @param array $agent The agent data
     * @return int|null The message ID if successful, null otherwise
     */
    protected function postMessage(string $token, array $messageContent, string $verb, bool $isPublic = true): ?int
    {
        $mutation = <<<GQL
        mutation createMessage(\$input: MessageInput!) {
          createMessage(input: \$input) {
            id
          }
        }
        GQL;

        $messageData = [
            'message_verb' => $verb,
            'message' => $messageContent,
            'is_public' => (int) $isPublic,
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
                            'input' => $messageData, // Changed from 'message' to 'input'
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

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Prism\Prism\Enums\Provider;

class SocialCreatorAgent extends BaseAgent
{
    protected ?string $authToken = null;
    protected ?string $graphqlUrl = null;
    protected array $sessionData = [];

    public function login(string $email, string $password): bool
    {
        try {
            $this->graphqlUrl = $this->app->get('graphql-url');

            $mutation = <<<GQL
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

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders(),
                'json' => [
                    'query' => $mutation,
                    'variables' => [
                        'data' => [
                            'email' => $email,
                            'password' => $password,
                        ],
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('Login failed', ['errors' => $result['errors']]);

                return false;
            }

            $this->authToken = 'Bearer ' . $result['data']['login']['token'];

            return true;
        } catch (Exception $e) {
            Log::error('Login failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function postMessage(array $messageContent, string $verb = 'prompt', bool $isPublic = true): ?int
    {
        if (! $this->authToken) {
            return null;
        }

        try {
            $mutation = <<<GQL
mutation createMessage(\$input: MessageInput!) {
  createMessage(input: \$input) {
    id
    uuid
    message
    created_at
  }
}
GQL;

            $messageData = [
                'message_verb' => $verb,
                'message' => $messageContent,
                'is_public' => (int) $isPublic,
            ];

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders([
                    'Authorization' => $this->authToken,
                    'X-Kanvas-App' => $this->app->key,
                    //'X-Kanvas-Key' => $this->app->keys()->first()->client_secret_id,
                ]),
                'json' => [
                    'query' => $mutation,
                    'variables' => [
                        'input' => $messageData,
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('GraphQL Error posting message', ['errors' => $result['errors']]);

                return null;
            }

            return (int) $result['data']['createMessage']['id'] ?? null;
        } catch (Exception $e) {
            Log::error('Failed to post message', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function generateViralPrompt(string $agentPersonality): ?array
    {
        $contentHistory = $this->getContentHistoryData(20, 30)['recent_posts'] ?? [];

        $promptHistory = '';
        foreach ($contentHistory as $history) {
            if (isset($history['title']) && isset($history['prompt'])) {
                $promptHistory .= "Title: {$history['title']}\nPrompt: {$history['prompt']}\nLikes: {$history['total_likes']}\n---------\n\n";
            }
        }

        $promptEngineering = <<<PROMPT
Role: You are a world-class prompt engineer specializing in creating viral, high-engagement, ONE-SHOT AI prompts. Your prompts are self-contained and require no follow-up. Your prompts are shared widely because they:
1. Solve urgent problems with razor-sharp specificity.
2. Leverage emerging trends (tech, culture, seasonal events) within relevant categories.
3. Elicit "stop the scroll" outputs (surprising, emotional, hyper-useful, or uniquely insightful).
4. Encourage sharing via clear customization hooks.

### Daily Task
Generate 1 self-contained, viral-worthy prompt based on the creator's personality and preferences described below.

IMPORTANT: Use the creator's bio as inspiration — not a constraint. Expand into adjacent or complementary topics that fit their interests, tone, and audience.

Before writing anything, carefully analyze the creator's previous content history to avoid repeating topics, formats, or hooks. Always aim for fresh, unique angles that align with their established voice.

Creator Bio: "$agentPersonality"  
Previous Content History: "$promptHistory"

#### Step 1: Trend Injection
- Consider these high-engagement categories and look for emerging trends within them:
    - Career/Professional Development (e.g., AI upskilling, remote work strategies)
    - Productivity Tools (e.g., AI assistants for specific tasks)
    - Personal Growth/Self-Improvement (e.g., building resilience in the digital age)
    - Education/Homework (e.g., AI for personalized learning)
    - Life Advice/Mental Health (e.g., managing digital overload)
- Combine relevant trends with the creator’s voice and content personality to propose original ideas, including crossovers from their interest sphere.

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
4. CTA: "To make this your own, [instruction for customization - e.g., 'replace [X] with your specific situation,' and include an example that will make for a fascinating, captivating use case for people to read in the output."
5. New lines must be separated with \\n

#### Step 3: Quality Check
- Stop the Scroll Test: Would this output immediately grab attention and elicit a strong reaction?
- Action Test: Can a user immediately understand and act upon the prompt?
- Share Trigger: Does it clearly invite and facilitate customization and sharing?
- Uniqueness Check: Does this prompt offer a fresh angle or novel application compared to past content?

### Final Output Format
Return ONLY a true JSON object, avoiding markdown and again make sure its TRUE JSON, not a stringified version. The output should look like this:
{
  "title": "The '[Compelling Hook]' Prompt: [Key Benefit]",
  "prompt": "[Structured prompt with Role, Goal, Constraints, CTA]",
  "target_LLM": "GPT-4o/Claude/Mixtral"
}
PROMPT;

        try {
            $message = new UserMessage($promptEngineering);
            $response = $this->chat($message);

            $responseText = str_replace(['```', 'json'], '', $response->getContent());
            $responseText = trim($responseText);

            $result = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Invalid AI response for prompt generation', [
                    'prompt' => $promptEngineering,
                    'response' => $responseText,
                ]);

                return [
                    'action' => 'error',
                    'reason' => 'Invalid response format',
                ];
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Prompt generation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'action' => 'error',
                'reason' => 'Generation failed: ' . $e->getMessage(),
            ];
        }
    }

    public function generateNugget(string $prompt): ?array
    {
        $nuggetGenerator = <<<ADVANCEPROMPT
# Atomic Execution Engine
You are a single-response AI that transforms prompts into complete, viral-ready outputs. Every response must:
1. Generate a complete, self-contained response to the prompt
2. Begin with a clear, descriptive title using the "# Title" format
3. Provide comprehensive content that fully addresses the prompt
4. Do NOT include phrases like "let me know if you need more" or "is there anything else"
5. Do NOT frame this as the beginning of a conversation
6. Maintain a length up to 3000 characters (not including title)
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
5. Maintain a length up to 3000 characters (not including title)

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
            $message = new UserMessage($nuggetGenerator);
            $response = $this->chat($message);

            $responseText = str_replace(['```', 'json'], '', $response->getContent());
            $responseText = trim($responseText);

            $result = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Invalid AI response for nugget generation', [
                    'response' => $responseText,
                ]);

                return null;
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Nugget generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getRandomModel(bool $freeOnly = true): ?array
    {
        $models = $this->app->get('llm_list_categorization_prod');

        if (! $models) {
            return null;
        }

        $allModelValues = [];

        // Extract all model values into a flat array
        foreach ($models as $category) {
            if (! isset($category['value'])) {
                continue;
            }

            foreach ($category['value'] as $provider) {
                if (! isset($provider['value'])) {
                    continue;
                }

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

    public function processContentCreation(array $agentConfig): array
    {
        $email = $agentConfig['email'];
        $password = $agentConfig['password'];
        $agentBio = $agentConfig['bio'] ?? '';
        $postsToCreate = $agentConfig['posts_per_session'] ?? 1;

        // Login
        if (! $this->login($email, $password)) {
            return [
                'success' => false,
                'error' => 'Login failed',
            ];
        }

        $totalCreated = 0;
        $totalFailed = 0;
        $createdPosts = [];

        for ($i = 0; $i < $postsToCreate; $i++) {
            try {
                // Generate a viral prompt using AI
                $promptData = $this->generateViralPrompt($agentBio);

                if (! $promptData || isset($promptData['action']) && $promptData['action'] === 'error') {
                    Log::error('Failed to generate prompt', ['attempt' => $i + 1]);
                    $totalFailed++;

                    continue;
                }

                // Generate nugget content
                /* $nugget = $this->generateNugget($promptData['prompt']);

                if (! $nugget) {
                    Log::error('Failed to generate nugget', ['attempt' => $i + 1]);
                    $totalFailed++;

                    continue;
                } */

                // Get a random model
                $model = $this->getRandomModel();

                // Prepare message structure
                $message = [
                    'title' => $promptData['title'],
                    'prompt' => $promptData['prompt'],
                    'is_assistant' => false,
                    'ai_model' => $model,
                    'ai_nugged' => [
                        'description' => null, //$nugget['description'] ?? '',
                        'title' => $promptData['title'],
                        'ai_model' => $model,
                        'nugget' => null,// $nugget['nugget'],
                        'id' => 2053,
                        'type' => 'text-format',
                        'created_at' => time() * 1000,
                        'updated_at' => time() * 1000,
                    ],
                    'type' => 'text-format',
                ];

                // Post the message
                /* $messageId = $this->postMessage($message, 'prompt', true);

                  if ($messageId) {
                     $totalCreated++;
                     $createdPosts[] = [
                         'message_id' => $messageId,
                         'title' => $promptData['title'],
                         'created_at' => date('Y-m-d H:i:s'),
                     ];

                     Log::info('Successfully created post', [
                         'message_id' => $messageId,
                         'title' => $promptData['title'],
                     ]);
                 } else {
                     $totalFailed++;
                     Log::error('Failed to post message', ['attempt' => $i + 1]);
                 } */

                $recipients = $this->app->get('test-creator-agent-email');
                if (empty($recipients)) {
                    //$this->error('No email address found for test creator agent. Exiting.');
                    $totalFailed++;
                }
                $subject = 'Test creator agent output - ' . $this->entity->email . ' - ' . date('Y-m-d H:i:s');
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

                // Add delay between posts to appear natural
                if ($i < $postsToCreate - 1) {
                    sleep(1); // 1 second
                }
            } catch (Exception $e) {
                Log::error('Content creation failed', [
                    'attempt' => $i + 1,
                    'error' => $e->getMessage(),
                ]);
                $totalFailed++;
            }
        }

        return [
            'success' => true,
            'total_created' => $totalCreated,
            'total_failed' => $totalFailed,
            'created_posts' => $createdPosts,
            'success_rate' => $postsToCreate > 0 ? round($totalCreated / $postsToCreate * 100, 1) : 0,
        ];
    }

    protected function getClient(): Client
    {
        return new Client([
            'verify' => false,
        ]);
    }

    protected function getHeaders(array $additional = []): array
    {
        return array_merge([
            'X-Kanvas-App' => $this->app->key,
            'X-Kanvas-Location' => '',
            'Content-Type' => 'application/json',
        ], $additional);
    }

    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), [
            Tool::make(
                'get_my_content_history',
                'Retrieve the content creation history for this user account. This tool provides access to recently created posts, including titles, engagement patterns, and content themes to maintain consistency and avoid repetition.',
            )->addProperty(
                new ToolProperty(
                    name: 'limit',
                    type: 'integer',
                    description: 'Number of recent posts to retrieve (default: 20, max: 50)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'days_back',
                    type: 'integer',
                    description: 'How many days back to look for posts (default: 30)',
                    required: false
                )
            )->setCallable(function (?int $limit = 20, ?int $days_back = 30) {
                return $this->getContentHistoryData($limit, $days_back);
            }),

            Tool::make(
                'analyze_trending_topics',
                'Analyze current trending topics and popular content themes to inform content creation decisions. This tool helps identify what types of content are performing well.',
            )->addProperty(
                new ToolProperty(
                    name: 'category',
                    type: 'string',
                    description: 'Content category to analyze (e.g., "productivity", "ai", "career")',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'timeframe',
                    type: 'string',
                    description: 'Timeframe for analysis (e.g., "week", "month")',
                    required: false
                )
            )->setCallable(function (?string $category = null, ?string $timeframe = 'week') {
                return $this->analyzeTrendingTopics($category, $timeframe);
            }),

            Tool::make(
                'generate_content_idea',
                'Generate a new content idea based on the agent personality, trending topics, and content history. This tool combines AI creativity with data-driven insights.',
            )->addProperty(
                new ToolProperty(
                    name: 'content_type',
                    type: 'string',
                    description: 'Type of content to generate (e.g., "prompt", "tutorial", "insight")',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'target_audience',
                    type: 'string',
                    description: 'Target audience for the content (e.g., "developers", "entrepreneurs", "students")',
                    required: false
                )
            )->setCallable(function (?string $content_type = 'prompt', ?string $target_audience = null) {
                return $this->generateContentIdea($content_type, $target_audience);
            }),

            Tool::make(
                'validate_content_quality',
                'Validate the quality and potential virality of generated content before posting. This tool checks for engagement potential, uniqueness, and alignment with agent personality.',
            )->addProperty(
                new ToolProperty(
                    name: 'title',
                    type: 'string',
                    description: 'Content title to validate',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'content',
                    type: 'string',
                    description: 'Content body to validate',
                    required: true
                )
            )->setCallable(function (string $title, string $content) {
                return $this->validateContentQuality($title, $content);
            }),
        ]);
    }

    protected function getContentHistoryData(?int $limit = 20, ?int $days_back = 30): array
    {
        Log::info('getContentHistoryData called', [
            'limit' => $limit,
            'days_back' => $days_back,
            'user_id' => $this->entity->id ?? 'unknown',
        ]);

        try {
            $limit = min(max(1, $limit ?? 20), 50);
            $days_back = min(max(1, $days_back ?? 30), 90);

            if (! $this->entity || ! $this->entity->id) {
                return [
                    'status' => 'error',
                    'message' => 'No user entity found',
                    'recent_posts' => [],
                    'total_posts_found' => 0,
                ];
            }

            $userId = $this->entity->id;
            $appId = $this->app->getId();

            // Get recent messages created by this user
            $messages = Message::where('users_id', $userId)
                ->where('apps_id', $appId)
                ->where('created_at', '>=', now()->subDays($days_back))
                ->where('is_deleted', 0)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            $contentHistory = [];
            foreach ($messages as $message) {
                $messageData = $message->message;

                $contentHistory[] = [
                    'message_id' => $message->id,
                    'title' => $messageData['title'] ?? 'No title',
                    'prompt' => $messageData['prompt'] ?? 'No content',
                    'total_likes' => $message->total_liked ?? 0,
                    'total_comments' => $message->total_shared ?? 0,
                    'nugget' => null, //$message->children->first() ? $message->children->first()->message : null,
                    'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                    'days_ago' => $message->created_at->diffInDays(now()),
                ];
            }

            return [
                'status' => 'success',
                'total_posts_found' => count($contentHistory),
                'recent_posts' => $contentHistory,
                'summary' => count($contentHistory) > 0 ?
                    'User has created ' . count($contentHistory) . ' posts recently' :
                    'No recent posts found',
            ];
        } catch (Exception $e) {
            Log::error('Failed to get content history', [
                'error' => $e->getMessage(),
                'user_id' => $this->entity->id ?? 'unknown',
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to retrieve content history: ' . $e->getMessage(),
                'recent_posts' => [],
                'total_posts_found' => 0,
            ];
        }
    }

    protected function analyzeTrendingTopics(?string $category = null, ?string $timeframe = 'week'): array
    {
        // This would implement actual trending analysis
        // For now, return a mock response
        return [
            'status' => 'success',
            'trending_topics' => [
                'AI productivity tools',
                'Remote work strategies',
                'Career development',
                'Personal branding',
            ],
            'category' => $category,
            'timeframe' => $timeframe,
        ];
    }

    protected function generateContentIdea(?string $content_type = 'prompt', ?string $target_audience = null): array
    {
        $prompt = "Generate a viral content idea for a {$content_type} targeting " .
                 ($target_audience ?? 'general audience') .
                 ". Consider current trends and the agent's personality: {$this->entity->description}";

        try {
            $message = new UserMessage($prompt);
            $response = $this->chat($message);

            return [
                'status' => 'success',
                'idea' => $response->getContent(),
                'content_type' => $content_type,
                'target_audience' => $target_audience,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to generate content idea: ' . $e->getMessage(),
            ];
        }
    }

    protected function validateContentQuality(string $title, string $content): array
    {
        $prompt = "Validate this content for viral potential and quality:\n\n" .
                 "Title: {$title}\n\n" .
                 "Content: {$content}\n\n" .
                 'Rate from 1-10 and provide feedback on: engagement potential, clarity, uniqueness, and shareability.';

        try {
            $message = new UserMessage($prompt);
            $response = $this->chat($message);

            return [
                'status' => 'success',
                'validation' => $response->getContent(),
                'title' => $title,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to validate content: ' . $e->getMessage(),
            ];
        }
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
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

        // Get trending data
        $trendingData = $this->getTrendingDataForPrompt();

        $promptHistory = '';
        foreach ($contentHistory as $history) {
            if (isset($history['title']) && isset($history['prompt'])) {
                $promptHistory .= "Title: {$history['title']}\nPrompt: {$history['prompt']}\nLikes: {$history['total_likes']}\n---------\n\n";
            }
        }

        $promptEngineering = <<<PROMPT
You are PromptAlchemist, a Viral Content Alchemist (VCA-Bot) who transforms raw trends and timeless human needs into golden, shareable AI experiences that solve real problems and spark conversation.

### Creator Bio
$agentPersonality

### Past Prompt History (Avoid Repeats)
$promptHistory

### Current Trending Data (Use For Trend Injection)
$trendingData

## MISSION: Create 1 Viral-Ready Prompt Blueprint

Your output must be scroll-stopping, screenshot-worthy, and inherently personalizable. It should create an *artifact* using a unique mechanism, driven by a specific emotional context.

---

## Step 1: Insight & Trend Vetting
A. **Find 1 Vetted Trend** using:
- One mainstream source (e.g., Twitter, Google Trends)
- Two non-mainstream sources (e.g., Substack, Reddit, Discord)

B. **Sentiment Check**
- What’s the core emotion behind this trend? (e.g., fear, grief, excitement)
- Discard trends involving tragedy, death, violence, or disaster (Mandatory)
- Controversial but safe trends must offer tools to *understand/navigate complexity*, not exploit it.

C. **Creative Collision**
Blend:
1. Vetted Trend  
2. Evergreen Desire (from category list below)  
3. Unexpected Format (e.g., escape room, screenplay, performance review)

---

## Step 2: Choose a Content Category
Pick 1:
- Career/Professional Development  
- Productivity Tools / Everyday Hacks  
- Personal Growth / Self-Improvement  
- Education / Homework Help  
- Life Advice / Mental Health  
- Community Connection  
- Viral-Worthy AI Image Art

---

## Step 3: Build the Prompt Blueprint

### Title
- 3-7 words only
- Must use conceptual or metaphorical hooks (not [Number] + [Steps] + [Result])
- Examples: “Turn Your Haters Into Fans”, “Design Your Future Like a Game”

### Prompt Structure
1. **Hook & Micro-Story**: Start with a situation that hits emotionally.
2. **AI Persona (Unexpected Expert)**: Assign a non-obvious, hyper-specific character (e.g., “K-pop Tour Manager”, “Olympic Sports Psychologist”).
3. **Mission & Artifact**: User should receive a clearly named deliverable (e.g., "Insight Map", "Launch Diagnostic", "Fan Conversion Script").
4. **Secret Sauce (Framework)**: Invent a new, named process the AI must use (e.g., "Critique, Stretch, Flip").
5. **<User_Input> as Raw Material**: Ask for an emotionally grounded, specific situation.

---

## Step 4: Anti-Crap Checklist
- Bans:
  - “Zen Unlock”, “Blueprint”, “3-Step Anything”, Pomodoro, “Digital Detox”, capsule wardrobes, financial clarity tropes
- Must:
  - Avoid Medium-style phrasing
  - Introduce metaphor, twist, or narrative POV
  - Deliver a unique and practical result, not just advice

---

## Step 5: Output Format

Return **ONLY** valid JSON:
{
  "title": "[3-7 word conceptual hook]",
  "prompt": "[Complete prompt with Hook, Persona, Artifact, Framework, and <User_Input>]",
  "target_LLM": "[GPT-4o / Claude / Mixtral] // Choose based on tone (creative, empathetic, technical)"
}

🎛 Choose LLM:
- GPT-4o: Creative hooks, wordplay, storytelling
- Claude: Empathetic, emotional processing, gentle tone
- Mixtral: Technical tools, logic-heavy analysis

Generate your viral prompt now. Output ONLY the final JSON.
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

            // Additional validation to ensure all required fields are present
            if (! isset($result['title']) || ! isset($result['prompt']) || ! isset($result['target_LLM'])) {
                Log::warning('Missing required fields in AI response', [
                    'response' => $result,
                ]);

                return [
                    'action' => 'error',
                    'reason' => 'Missing required fields in response',
                ];
            }

            // Validate title length (3-7 words)
            $titleWordCount = str_word_count($result['title']);
            if ($titleWordCount < 3 || $titleWordCount > 7) {
                Log::warning('Title word count validation failed', [
                    'title' => $result['title'],
                    'word_count' => $titleWordCount,
                ]);

                return [
                    'action' => 'error',
                    'reason' => 'Title must be 3-7 words in length',
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

    /**
     * Get trending data formatted for prompt injection
     */
    private function getTrendingDataForPrompt(): string
    {
        $trendingData = "=== TWITTER/X TRENDING TOPICS ===\n";

        // Get Twitter trends
        $twitterTrends = $this->getTwitterTrends();
        if (! empty($twitterTrends)) {
            foreach (array_slice($twitterTrends, 0, 8) as $index => $trend) {
                $trendTitle = is_string($trend) ? $trend : ($trend['title'] ?? 'Unknown');
                $trendingData .= ($index + 1) . ". {$trendTitle}\n";
            }
        } else {
            $trendingData .= "- No Twitter trends available\n";
        }

        $trendingData .= "\n=== REDDIT HOT TOPICS ===\n";

        // Get Reddit trends
        $redditTrends = $this->getRedditTrending();
        if (! empty($redditTrends)) {
            foreach (array_slice($redditTrends, 0, 6) as $index => $trend) {
                $trendTitle = is_string($trend) ? $trend : ($trend['title'] ?? 'Unknown');
                $score = isset($trend['score']) ? " ({$trend['score']} upvotes)" : '';
                $trendingData .= ($index + 1) . ". {$trendTitle}{$score}\n";
            }
        } else {
            $trendingData .= "- No Reddit trends available\n";
        }

        $trendingData .= "\n=== TREND INJECTION INSTRUCTIONS ===\n";
        $trendingData .= "- Pick 1-2 trending topics from above that could be transformed into a viral prompt\n";
        $trendingData .= "- Add an unexpected twist or unique angle to make it prompt-worthy\n";
        $trendingData .= "- Focus on topics that solve real problems or provide practical value\n";
        $trendingData .= "- Avoid political controversies or sensitive topics\n\n";

        return $trendingData;
    }

    /**
     * Get Twitter trending topics (free aggregators)
     */
    private function getTwitterTrends(): array
    {
        // Try multiple free sources
        $sources = [
            'https://trends24.in/api/trends/united-states',
            'https://xtrends.iamrohit.in/api/trends/united-states',
        ];

        foreach ($sources as $source) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TrendBot/1.0)'])
                    ->get($source);

                if ($response->successful()) {
                    $data = $response->json();

                    return $data['trends'] ?? $data ?? [];
                }
            } catch (Exception $e) {
                continue; // Try next source
            }
        }

        // Fallback: scrape trends24.in directly
        return $this->scrapeTrends24();
    }

    /**
     * Get Reddit trending posts
     */
    private function getRedditTrending(): array
    {
        try {
            // Get r/all hot posts
            $response = Http::timeout(10)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TrendBot/1.0)',
            ])->get('https://www.reddit.com/r/all/hot.json?limit=15');

            if ($response->successful()) {
                $data = $response->json();

                return array_map(function ($post) {
                    return [
                        'title' => $post['data']['title'],
                        'score' => $post['data']['score'],
                        'subreddit' => $post['data']['subreddit'],
                        'url' => $post['data']['url'] ?? null,
                    ];
                }, $data['data']['children'] ?? []);
            }
        } catch (Exception $e) {
            Log::warning('Reddit API failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Scrape trends24.in for Twitter trends
     */
    private function scrapeTrends24(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TrendBot/1.0)'])
                ->get('https://trends24.in/united-states/');

            if (! $response->successful()) {
                return [];
            }

            $html = $response->body();
            $trends = [];

            // Parse trending topics from HTML
            preg_match_all('/<li[^>]*>.*?<a[^>]*>([^<]+)<\/a>.*?<\/li>/s', $html, $matches);

            if (isset($matches[1])) {
                foreach (array_slice($matches[1], 0, 15) as $trend) {
                    $cleanTrend = trim(strip_tags($trend));
                    if (! empty($cleanTrend) && strlen($cleanTrend) > 2) {
                        $trends[] = [
                            'title' => $cleanTrend,
                            'platform' => 'twitter',
                            'source' => 'trends24',
                        ];
                    }
                }
            }

            return $trends;
        } catch (Exception $e) {
            Log::warning('Trends24 scraping failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function generateNugget(string $prompt): ?array
    {
        $nuggetGenerator = <<<ADVANCEPROMPT
You are a Viral Content Alchemist (VCA-Bot) executing a transformation prompt.

Your mission is to generate a single viral-ready, screenshot-worthy response based on the following rules:

### STRUCTURE (Do Not Deviate)
1. Title: Start with `# [Unexpected Twist] [Familiar Concept]` — must be catchy and under 70 characters.
2. Hook: ⚡ One-liner that makes users pause, feel, or laugh.
3. Nuggets: 3–5 crisp bullets with either surprising insights, emotional reframes, or highly actionable steps.
4. Template: Include 1 reusable prompt, code block, or script that users will want to steal.
5. CTA: End with a clear line encouraging personalization, e.g., “Swap the bold part with your situation and run it.”
6. Format all line breaks with \\n — no actual newlines.

### VOICE & STYLE
- Avoid fluff. Surprise or impress in every line.
- Use either playful, bold, or dead-serious tone depending on original prompt.
- Every bullet should have a strong “aha”, “oh damn”, or “I need this” effect.
- No weak filler. No vague “tips”. No inspirational quotes.

### PROHIBITED ELEMENTS
- Don’t begin or end with conversational filler.
- Don’t ask the user questions in the nugget.
- Don’t say “as an AI…” or similar disclaimers.

### OUTPUT FORMAT
Only return a valid JSON like this:
{
  "title": "[Under 70 chars]",
  "nugget": "[⚡Hook]\\n[• Insight 1]\\n[• Insight 2]\\n[• Insight 3]\\n[Template]\\n[CTA]",
  "engagement_hook": "[Controversial or funny question to provoke replies]",
  "completeness_score": 1-10
}

### PROMPT TO EXECUTE
$prompt
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
                    type: PropertyType::INTEGER,
                    description: 'Number of recent posts to retrieve (default: 20, max: 50)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'days_back',
                    type: PropertyType::INTEGER,
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
                    type: PropertyType::STRING,
                    description: 'Content category to analyze (e.g., "productivity", "ai", "career")',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'timeframe',
                    type: PropertyType::STRING,
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
                    type: PropertyType::STRING,
                    description: 'Type of content to generate (e.g., "prompt", "tutorial", "insight")',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'target_audience',
                    type: PropertyType::STRING,
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
                    type: PropertyType::STRING,
                    description: 'Content title to validate',
                    required: true
                )
            )->addProperty(
                new ToolProperty(
                    name: 'content',
                    type: PropertyType::STRING,
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

<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GenerateImageToImageTool extends Tool
{
    protected string $name = 'generate_image_to_image';

    protected string $description = <<<'MARKDOWN'
        Generate an image from a prompt and an existing image using Generative AI API. Returns structured image data including
        URL, processing status, and metadata.
    MARKDOWN;

    public function handle(Request $request): ResponseFactory
    {
        $maxRetries = 3;
        $retryDelay = 2; // seconds
        $attempt = 0;
        $success = false;
        $response = null;

        $app = app(Apps::class);
        $apiUrl = 'https://prompt-mine-ai-api-stage.vercel.app/api/image/openai';

        Log::info('GenerateImageToImageTool request', ['prompt' => $request->get('prompt')]);

        while ($attempt < $maxRetries && ! $success) {
            try {
                $response = Http::timeout(200)->withHeaders([
                    // 'Authorization' => 'Bearer ' . $token,
                    'X-Kanvas-App' => $app->key,
                ])->post($apiUrl, [
                    'prompt' => $request->get('prompt'),
                    'model' => 'chatgpt-image-latest',
                    'quality' => 'medium',
                    'image_url' => $request->get('image_url')
                ]);
                $success = true;
            } catch (\Exception $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {

                    return Response::structured([
                        'data' => null,
                        'error' => $e->getMessage()
                    ]);
                }

                // Wait before retrying
                sleep($retryDelay);

                // Increase the delay for next attempt (exponential backoff)
                $retryDelay *= 2;
            }
        }

        Log::info('GenerateImageToImageTool response', ['response' => $response->json()]);

        return Response::structured([
            'data' => $response ? $this->transformResponse($response->json()) : null,
            'error' => null
        ]);
    }

    /**
     * Transform a message model into structured content.
     *
     * @return array<string, mixed>
     */
    protected function transformResponse(Array $response): array
    {
        return [
            'file_name' => $response['file_name'] ?? null,
            'content_type' => $response['content_type'] ?? null,
            'url' => $response['url'] ?? null,
            'success' => $response['success'] ?? null,
        ];
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            "prompt" => $schema->string()->description('Text prompt to generate the image from'),
            "image_url" => $schema->string()->description('URL of image(input)'),
        ];
    }

    /**
     * Define the output schema so the AI knows what to expect.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $outputSchema = $schema->object([
            'file_name' => $schema->string()->description('The name of the generated image file'),
            'content_type' => $schema->string()->description('The content type of the generated image (e.g., image/png)'),
            'url' => $schema->string()->description('URL of the generated image'),
            'success' => $schema->boolean()->description('Whether the image generation was successful'),
        ]);

        return [
            'data' => $outputSchema->description('The output data for image generation')->nullable(true),
            'error' => $schema->string()->description('Error message in case something goes wrong')->nullable(true)
        ];
    }
}

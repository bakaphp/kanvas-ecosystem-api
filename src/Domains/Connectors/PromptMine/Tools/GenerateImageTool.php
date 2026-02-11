<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GenerateImageTool extends Tool
{
    protected string $name = 'generate_image';

    protected string $description = <<<'MARKDOWN'
        Generate an image from a prompt using Generative AI API. Returns structured image data including
        URL, processing status, and metadata.
    MARKDOWN;

    public function handle(Request $request): ResponseFactory
    {
        // $user = auth()->user();
        // $token = auth()->login($user);
        $apiUrl = 'https://prompt-mine-ai-api-stage.vercel.app/api/image/openai';
        $response = Http::post($apiUrl, [
            'headers' => [
                // 'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'prompt' => $request->get('prompt'),
                'model' => "dall-e-3",
                'quality' => "medium",
                "image_url" => $request->get('image_url'),
            ],
        ]);

        return Response::structured([
            'data' => $response ? $this->transformResponse($response->json()) : null,
            'meta' => [
                'success' => $response ? true : false,
            ],
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
            "image_url" => $schema->string()->description('URL of the generated image (output)'),
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
            'data' => $outputSchema->description('The output data for image generation'),
            'meta' => $schema->object([
                "success" => $schema->boolean()->description('Whether the image generation was successful'),
            ])->description('Metadata about the response'),
        ];
    }
}

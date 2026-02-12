<?php

namespace App\Mcp\Servers;

use Kanvas\Connectors\PromptMine\Tools\GenerateImageToImageTool;
use Laravel\Mcp\Server;
use Kanvas\Connectors\PromptMine\Tools\GenerateImageTool;

class GenerativeAIServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Generative AI Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.0.1';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        This server provides tools for generating images using Generative AI.
        Use the provided tools to create images based on text prompts or modify existing images.
        Available tools:
        - `generate_image`: Generate an image from a text prompt.
        - `generate_image_to_image`: Generate an image from a text prompt and an existing image
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        GenerateImageTool::class,
        GenerateImageToImageTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        //
    ];
}

<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;

class KanvasGeminiGateway extends GeminiGateway
{
    protected function buildTextRequestBody(
        Provider $provider,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        [$body, $contents] = parent::buildTextRequestBody(
            $provider,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $this->injectServerSideToolFlag($body, $tools);

        return [$body, $contents];
    }

    protected function rebuildContinuationBody(
        array $contents,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        $body = parent::rebuildContinuationBody(
            $contents,
            $instructions,
            $tools,
            $schema,
            $options,
            $provider,
        );

        $this->injectServerSideToolFlag($body, $tools);

        return $body;
    }

    private function injectServerSideToolFlag(array &$body, array $tools): void
    {
        $hasServerSideTools = collect($tools)->some(fn ($t) => $t instanceof ProviderTool);

        if ($hasServerSideTools && isset($body['tool_config'])) {
            $body['tool_config']['include_server_side_tool_invocations'] = true;
        }
    }
}

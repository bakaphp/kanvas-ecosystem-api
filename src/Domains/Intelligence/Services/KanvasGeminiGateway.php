<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Override;

class KanvasGeminiGateway extends GeminiGateway
{
    #[Override]
    protected function buildTextRequestBody(
        Provider $provider,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = parent::buildTextRequestBody(
            $provider,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $this->injectServerSideToolFlag($body, $tools);

        return $body;
    }

    private function injectServerSideToolFlag(array &$body, array $tools): void
    {
        $hasServerSideTools = collect($tools)->some(fn ($t) => $t instanceof ProviderTool);

        if ($hasServerSideTools) {
            $body['tool_config']['include_server_side_tool_invocations'] = true;
        }
    }
}

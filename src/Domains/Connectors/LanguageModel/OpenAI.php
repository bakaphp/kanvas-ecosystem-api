<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LanguageModel;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\LanguageModel\Enums\ConfigurationEnum;
use OpenAI as GlobalOpenAI;
use OpenAI\Client;
use OpenAI\Factory;

class OpenAI
{
    protected string $apiKey;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->apiKey = $this->app->get(ConfigurationEnum::OPENAI_API_KEY->value);

        if (empty($this->apiKey)) {
            throw new Exception('OpenAI API Key is required');
        }
    }

    public function client(): Client
    {
        return GlobalOpenAI::client($this->apiKey);
    }

    public function factory(): Factory
    {
        return GlobalOpenAI::factory()->withApiKey($this->apiKey);
    }
}

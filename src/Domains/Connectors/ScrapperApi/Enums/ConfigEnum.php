<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

use Baka\Contracts\AppInterface;

enum ConfigEnum: string
{
    case SCRAPPER_API_KEY = 'scraper_api_key';
    case AMAZON_ID = 'amazon_id';
    case ACTIVITY_QUEUE = 'scrapper-queue';

    case DEFAULT_QUANTITY = 'default_quantity';

    case WORDLIST = 'wordlist_';

    public static function getWordEnum(AppInterface $app, string $word): string
    {
        return ConfigEnum::WORDLIST->value . "{$app->getId()}_" . $word;
    }
}

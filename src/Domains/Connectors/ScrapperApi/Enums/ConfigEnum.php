<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

enum ConfigEnum: string
{
    case SCRAPPER_API_KEY = 'scraper_api_key';
    case AMAZON_ID = 'amazon_id';
    case ACTIVITY_QUEUE = 'scrapper-queue';
}

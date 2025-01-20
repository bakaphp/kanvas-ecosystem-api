<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Enums;

use Baka\Contracts\AppInterface;

enum ConfigEnum: string
{
    case SCRAPPER_API_KEY = 'scraper_api_key';
    case AMAZON_ID = 'amazon_id';
    case AMAZON_PRICE = 'amazon_price';
    case ACTIVITY_QUEUE = 'scrapper-queue';

    case DEFAULT_QUANTITY = 'default_quantity';

    case WORDLIST = 'wordlist_';

    case SEARCHED_FIELD = 'searched_';

    case SCRAPPER_SECONDS = 'scrapper_seconds';

    case SCRAPPER_SHIPPING = 'scrapper_shipping';
    case SCRAPPER_SHIPPING_AMAZON = 'scrapper_shipping_amazon';

    public static function getWordEnum(AppInterface $app): string
    {
        return ConfigEnum::WORDLIST->value . "{$app->getId()}";
    }
}

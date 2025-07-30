<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Enums;

enum ConfigEnum: string
{
    case SCRAPING_DOG_API_KEY = 'scraping_dog_api_key';
    case AMAZON_ID = 'amazon_id';
    case AMAZON_PRICE = 'amazon_price';
    case DEFAULT_QUANTITY = 'default_quantity';
    case SCRAPPER_BRAND = 'scrapper_brand';
    case SCRAPPER_RATING = 'scrapper_rating';
}

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
    case VARIANT_PRICE_UPDATE = 'variant_price_update';
    case VARIANT_PRICE_DATE_UPDATE = 'variant_price_date_update';
    case VARIANT_DOWNLOAD = 'variant_download';
}

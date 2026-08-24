<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Enums;

enum CustomFieldEnum: string
{
    case POST_ID = 'WORDPRESS_POST_ID';
    case POST_URL = 'WORDPRESS_POST_URL';
    case POST_STATUS = 'WORDPRESS_POST_STATUS';
    case POST_SITE_URL = 'WORDPRESS_POST_SITE_URL';
    case FEATURED_MEDIA_ID = 'WORDPRESS_FEATURED_MEDIA_ID';
    case MEDIA_IDS = 'WORDPRESS_MEDIA_IDS';
}

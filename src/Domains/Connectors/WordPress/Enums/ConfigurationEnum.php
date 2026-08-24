<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'WordPress';
    case DEALERS = 'wordpress_inventory_dealers';
    case FTP_HOST = 'wordpress_ftp_host';
    case FTP_USERNAME = 'wordpress_ftp_username';
    case FTP_PASSWORD = 'wordpress_ftp_password';
    case FTP_PORT = 'wordpress_ftp_port';
    case FTP_ROOT = 'wordpress_ftp_root';
    case FTP_SSL = 'wordpress_ftp_ssl';
    case FTP_PROTOCOL = 'wordpress_ftp_protocol';

    case SITE_URL = 'wordpress_site_url';
    case USERNAME = 'wordpress_username';
    case APPLICATION_PASSWORD = 'wordpress_application_password';
    case DEFAULT_POST_STATUS = 'wordpress_default_post_status';
    case DEFAULT_AUTHOR_ID = 'wordpress_default_author_id';
    case DEFAULT_CATEGORIES = 'wordpress_default_categories';
    case DEFAULT_TAGS = 'wordpress_default_tags';
    case ALLOW_TERM_CREATION = 'wordpress_allow_term_creation';
}

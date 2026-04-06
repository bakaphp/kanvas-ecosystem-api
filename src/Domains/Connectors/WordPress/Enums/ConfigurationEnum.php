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
}

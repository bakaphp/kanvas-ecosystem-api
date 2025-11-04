<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Enums;

enum CustomFieldEnum: string
{
    case DEALER_SOCKET_CREDENTIAL = 'dealer_socket_credential';
    case DEALER_SOCKET_PUBLIC_KEY = 'dealer_socket_public_key';
    case DEALER_SOCKET_PRIVATE_KEY = 'dealer_socket_private_key';
    case DEALER_SOCKET_DEALER_ID = 'dealer_socket_dealer_id';
    case DEALER_SOCKET_USERNAME = 'dealer_socket_username';
    case DEALER_SOCKET_PASSWORD = 'dealer_socket_password';
    case DEALER_SOCKET_VENDOR_NAME = 'dealer_socket_vendor_name';
}

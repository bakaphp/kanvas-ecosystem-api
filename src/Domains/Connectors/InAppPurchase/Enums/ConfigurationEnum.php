<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum ConfigurationEnum: string
{
    case APPLE_PAYMENT_SHARED_SECRET = 'APPLE_PAYMENT_SHARED_SECRET';
    case GOOGLE_PLAY_PACKAGE_NAME = 'GOOGLE_PLAY_PACKAGE_NAME';
    case APPLE_BUNDLE_ID = 'APPLE_BUNDLE_ID';
    case REVENUECAT_SECRET_KEY = 'REVENUECAT_SECRET_KEY';
    case REVENUECAT_PROJECT_ID = 'REVENUECAT_PROJECT_ID';
    // Local and factory test apps only: accepts transactions signed by Xcode's StoreKit testing certificate.
    case IAP_ALLOW_XCODE_STOREKIT_TESTING = 'IAP_ALLOW_XCODE_STOREKIT_TESTING';
}

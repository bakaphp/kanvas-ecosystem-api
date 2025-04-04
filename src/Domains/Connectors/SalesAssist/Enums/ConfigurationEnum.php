<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

enum ConfigurationEnum: string
{
    case CONTENT = 'dealer-content';
    case DOWNLOAD_APP = 'download-app';
    case DOWNLOAD_PRODUCT = 'download-vehicle';
    case TRADE_WALK = 'trade-walk';
    case VIEW_PRODUCT = 'view-vehicle';
    case GET_DOCS = 'get-docs';
    case CREDIT_APP = 'credit-app';
    case CREDIT_APP_2 = 'credit-app-2';
    case CREDIT_APP_3 = 'credit-app-3';
    case CREDIT_APP_4 = 'credit-app-4';
    case CREDIT_APP_5 = 'credit-app-5';
    case BUSINESS_CREDIT_APP = 'business-credit-app';
    case GET_REFERRAL = 'get-referrals';
    case ADD_TRADE = 'add-trade';
    case ESIGN_DOCS = 'esign-docs';
    case LEGAL_DOCUMENTS = 'legal-documents';
    case SEARCH_HUB = 'search-hub';
    case NEEDS_ANALYSIS = 'needs-analysis';
    case PAYOFF_FORM = 'payoff-form';
    case VALID_SOLD = 'validate-sold';
    case CO_SIGNER = 'co-signer';
    case CO_SIGNER_2 = 'co-signer-2';
    case CO_SIGNER_3 = 'co-signer-3';
    case CO_SIGNER_4 = 'co-signer-4';
    case CO_SIGNER_5 = 'co-signer-5';
    case IN_STORE_VISIT = 'in-store-visit';
    case CREDIT_CARD_AUTHORIZATION = 'cc-auth';
    case ELECTRONIC_DOCUMENTS_CUSTOM_FIELD = 'actions_pdf_custom_form';
    case CREDIT_APP_CUSTOM_FIELD = 'credit_app_custom_form';
    case CO_SIGNER_APP_CUSTOM_FIELD = 'co_signer_custom_form';
    case DIGITAL_CARD = 'digital-card';
    case GET_REVIEW = 'get-reviews';
    case COOKIE_REVIEW = 'cookie-reviews';
    case CAPSULE = 'capsule';
    case PURE_CARS = 'pure-cars';
    case I_PACKET = 'i-packet';
    case CSI_APPROVAL = 'csi-approval';
    case SOFT_PULL = 'soft-pull';
    case ID_VERIFICATION = 'id-verification';
    case OFAC = 'ofac';
    case TURN_AUTO = 'turnauto';
    case CREDIT_700 = '700-credit';
    case FILE_SHARING = 'file-sharing';
    case CREDIT_CONSENT = 'credit-consent';
    case VEHICLE_ORDER = 'vehicle-order';
    case PURCHASE_VEHICLE = 'purchase-vehicle';
    case SHARE_BLUELINK = 'share-code';
    case SHARE_ELECTRIFY_AMERICA = 'share-electrify-america';
    case PAYOFF_VERIFICATION = 'payoff-verification';
    case INSURANCE_VERIFICATION = 'insurance-verification';
    case SOLD_CAR_VERIFICATION = 'sold-car-verification';
    case UPLOAD_DOCS = 'upload-docs';
    case MILEAGE_VERIFICATION = 'mileage-verification';
    case LOANER_CAR_AGREEMENT = 'loaner-car-agreement';
    case FINANCE_AND_INSURANCE = 'finance-and-insurance';
    case MILEAGE_CONFIRMATION = 'mileage-confirmation';
}

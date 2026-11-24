<?php

declare(strict_types=1);

use Kanvas\Connectors\Alanube\AlanubeEcfProvider;
use Kanvas\Connectors\AllRatesToday\AllRatesTodayCentralBankRateProvider;
use Kanvas\Connectors\AllRatesToday\AllRatesTodayMarketRateProvider;
use Kanvas\Connectors\WebPos\Providers\WebPosEcfProvider;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;

return [
    /*
     * Electronic-invoicing regimes by ISO country (FiscalRegimeInterface). A country's rules live only in its
     * class under Scribe/ElectronicInvoicing/Regimes/{Country}/.
     */
    'fiscal_regimes' => [
        'DO' => DrFiscalRegime::class,
    ],

    /*
     * e-CF provider implementations, keyed by the company setting `scribe-ecf-provider`
     * (Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum::ECF_PROVIDER). Each class implements
     * EcfProviderInterface, names the country it issues for (`country()`), and is constructed as
     * `new $class(app: $app, company: $company)`.
     */
    'ecf_providers' => [
        'webpos' => WebPosEcfProvider::class,
        'alanube' => AlanubeEcfProvider::class,
    ],

    /*
     * Where the official daily exchange rate comes from (CentralBankRateProviderInterface). It declares the
     * DOP value of a foreign-currency e-CF; the invoice itself stays in its own currency.
     */
    'fx_rate_provider' => AllRatesTodayCentralBankRateProvider::class,

    // Foreign currencies whose official rate is synced daily, for every country with a fiscal regime.
    'fx_currencies' => ['USD'],

    /*
     * Where a live market rate for any pair comes from (MarketRateProviderInterface). Answers a question only;
     * a fiscal document never declares it.
     */
    'market_rate_provider' => AllRatesTodayMarketRateProvider::class,
];

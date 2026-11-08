<?php

declare(strict_types=1);

use Kanvas\Connectors\Alanube\AlanubeEcfProvider;
use Kanvas\Connectors\AllRatesToday\AllRatesTodayCentralBankRateProvider;
use Kanvas\Connectors\WebPos\Providers\WebPosEcfProvider;

return [
    /*
     * e-CF provider implementations, keyed by the company setting `scribe-ecf-provider`
     * (Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum::ECF_PROVIDER). Each class implements
     * EcfProviderInterface and is constructed as `new $class(app: $app, company: $company)`.
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
];

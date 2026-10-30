<?php

declare(strict_types=1);

namespace Tests\Connectors\Portal\Concerns;

trait BuildsEnrollmentResponses
{
    protected function consumerAuthBase(): array
    {
        return [
            'indicator' => null,
            'authenticationTransactionId' => null,
            'eciRaw' => null,
            'authenticationResult' => null,
            'strongAuthentication' => [],
            'authenticationStatusMsg' => null,
            'eci' => null,
            'token' => '',
            'accessToken' => null,
            'cavv' => null,
            'paresStatus' => null,
            'xid' => null,
            'directoryServerTransactionId' => null,
            'threeDSServerTransactionId' => null,
            'specificationVersion' => null,
            'acsTransactionId' => null,
            'ucafCollectionIndicator' => null,
            'ucafAuthenticationData' => null,
            'challengeRequired' => null,
            'acsUrl' => null,
            'acsReferenceNumber' => null,
            'stepUpUrl' => null,
            'pareq' => null,
            'veresEnrolled' => null,
            'acsOperatorID' => null,
        ];
    }
}

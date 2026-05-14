<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use DomainException;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Override;

final class PayWayTokenizationService implements TokenizationProcessorInterface
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    #[Override]
    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        throw new DomainException(
            'PayWay does not support standalone tokenization. Saved cards are created during checkout — pass save_card=true in the charge context.'
        );
    }

    #[Override]
    public function deleteToken(string $token): bool
    {
        $rows = PaymentMethods::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('processor', 'payway')
            ->where('is_deleted', 0)
            ->whereJsonContains('metadata->payway_numero_uuid', $token)
            ->get();

        foreach ($rows as $row) {
            $row->softDelete();
        }

        return true;
    }
}

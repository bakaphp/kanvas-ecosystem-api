<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CanonicalQuoteProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\Enums\InsuranceSearchFailureReasonEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Throwable;

final class PlanQuoteTask
{
    use KanvasJobsTrait;

    public function __construct(
        private readonly string $provider,
        private readonly int $appId,
        private readonly int $credentialCompanyId,
        private readonly CanonicalQuoteInput $input,
        private readonly string $planCode,
        private readonly ?string $vehicleModelId,
    ) {
    }

    public function __invoke(): array
    {
        try {
            $app = Apps::getById($this->appId);
            $this->overwriteAppService($app);

            $provider = InsuranceProviderFactory::make(
                $this->provider,
                $app,
                Companies::getById($this->credentialCompanyId)
            );

            if (! $provider instanceof CanonicalQuoteProviderInterface) {
                return ['reason' => InsuranceSearchFailureReasonEnum::UNAVAILABLE];
            }

            return ['quote' => $provider->quoteCanonical($this->input, $this->planCode, $this->vehicleModelId)];
        } catch (ValidationException) {
            return ['reason' => InsuranceSearchFailureReasonEnum::REJECTED];
        } catch (Throwable $e) {
            Log::warning('Insurance search quote failed', [
                'provider' => $this->provider,
                'plan' => $this->planCode,
                'exception' => $e::class,
                'status' => $this->httpStatus($e),
            ]);

            return ['reason' => InsuranceSearchFailureReasonEnum::UNAVAILABLE];
        }
    }

    private function httpStatus(Throwable $e): ?int
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

        return $response !== null && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;
    }
}

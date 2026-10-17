<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\AttachQuoteToOrderAction;
use Kanvas\Insurance\Actions\ConfirmQuoteWithInsurerAction;
use Kanvas\Insurance\Actions\ReadInsuranceOrderAction;
use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Actions\StampInsuranceQuoteAction;
use Kanvas\Insurance\Actions\ValidateInsuranceOrderAction;
use Kanvas\Insurance\Concerns\FailsReleasingHeldPayment;
use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceOrderData;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\DataTransferObject\VerifiedInsuranceOrder;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceCommissionCalculator;
use Kanvas\Insurance\Services\InsuranceOrderMetadata;
use Kanvas\Insurance\Services\InsuranceRequestId;
use Kanvas\Insurance\Services\InsuranceStatus;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction]
class AttachInsuranceQuoteActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use FailsReleasingHeldPayment;

    private const string INSURER_REJECTED = 'The insurer could not accept the quote or the customer information, please review it and try again';

    private const string UNAVAILABLE = 'Insurance is not available right now, please try again later';

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => InsuranceOrderOriginEnum::PURCHASE_FLOW_SKIPPED,
            ];
        }

        $isInsuranceOrder = InsuranceOrderTypeEnum::INSURANCE->isTypeOf($order);

        if (! $isInsuranceOrder && ! $this->hasLegacyBlock($order)) {
            return $this->skipped($order, 'Order carries no insurance quote');
        }

        if (InsuranceStatus::hasQuote($order) || InsuranceStatus::of($order) === InsuranceStatusEnum::FAILED) {
            return $this->skipped($order, 'Quote already attached to this order');
        }

        try {
            $data = new ReadInsuranceOrderAction($order)->execute();
        } catch (InsuranceValidationException $e) {
            return $isInsuranceOrder ? $this->failInvalid($order, $e->getMessage()) : $this->skipped($order, 'Order carries no insurance quote');
        } catch (Throwable $e) {
            return $isInsuranceOrder ? $this->failUnexpected($order, $e) : throw $e;
        }

        if ($data->legacy) {
            return $this->attachLegacy(
                $order,
                $app,
                $params,
                $data,
                $isInsuranceOrder
            );
        }

        return $this->validateAndAttach(
            $order,
            $app,
            $params,
            $data
        );
    }

    private function validateAndAttach(
        Model $order,
        AppInterface $app,
        array $params,
        InsuranceOrderData $data
    ): array {
        try {
            return $this->validateAndStamp(
                $order,
                $app,
                $params,
                $data
            );
        } catch (Throwable $e) {
            return $this->failUnexpected($order, $e);
        }
    }

    private function validateAndStamp(
        Model $order,
        AppInterface $app,
        array $params,
        InsuranceOrderData $data
    ): array {
        try {
            $verified = new ValidateInsuranceOrderAction($order, $data)->execute();
        } catch (InsuranceValidationException $e) {
            return $this->failInvalid($order, $e->getMessage());
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $verified->provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($verified, $data) {
                try {
                    $quote = new ConfirmQuoteWithInsurerAction(
                        $verified->provider,
                        (string) $data->quoteNumber,
                        (string) $verified->plan->external_code,
                        $data->quoteRequest($verified->provider),
                        InsuranceRequestId::for('insurance-order-' . $order->getId())
                    )->execute();
                    $verified->assertPriceMatches($quote);
                } catch (InsuranceValidationException $e) {
                    return $this->failInvalid($order, $e->getMessage());
                } catch (ValidationException $e) {
                    return $this->failInvalid($order, self::INSURER_REJECTED, $e->getMessage());
                }

                $this->stampValidated(
                    $order,
                    $verified,
                    $data,
                    $quote
                );

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'provider' => $verified->provider->name(),
                    'quoteNumber' => $quote->quoteNumber,
                    'total' => $quote->total,
                ];
            },
            company: $order->company,
        );
    }

    private function stampValidated(
        Model $order,
        VerifiedInsuranceOrder $verified,
        InsuranceOrderData $data,
        QuoteResult $quote
    ): void {
        new StampInsuranceQuoteAction(
            order: $order,
            providerName: $verified->provider->name(),
            productCode: (string) $verified->plan->external_code,
            quote: $quote,
            commission: InsuranceCommissionCalculator::effective($quote, (float) $verified->plan->commission_rate),
        )->execute();

        $order->set(InsuranceCustomFieldEnum::PLAN_ID->value, (string) $verified->plan->getId());
        $order->metadata = InsuranceOrderMetadata::apply($order->metadata, $quote->quoteNumber, $data->customer);
        $order->saveQuietly();
    }

    private function attachLegacy(
        Model $order,
        AppInterface $app,
        array $params,
        InsuranceOrderData $data,
        bool $isInsuranceOrder
    ): array {
        if (! $isInsuranceOrder && ! $data->legacyComplete) {
            return $this->skipped($order, 'Order carries no insurance quote');
        }

        if (! $data->isIdentified()) {
            return $this->failInvalid($order, 'This insurance order has no plan or quote to attach');
        }

        $provider = InsuranceProviderFactory::make((string) $data->provider, $app, $order->company);

        if ($isInsuranceOrder && $provider instanceof CustomerDataProviderInterface && ! $data->hasCustomerData()) {
            return $this->failInvalid($order, 'Customer information is required to contract insurance');
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($data) {
                $quote = new AttachQuoteToOrderAction($order)->execute();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'provider' => $data->provider,
                    'quoteNumber' => $quote->quoteNumber,
                    'total' => $quote->total,
                ];
            },
            company: $order->company,
        );
    }

    private function hasLegacyBlock(Model $order): bool
    {
        return is_array($order->metadata) && ! empty($order->metadata['insurance']);
    }

    private function failUnexpected(Model $order, Throwable $e): array
    {
        report($e);

        $order->set(InsuranceCustomFieldEnum::FAILURE_REASON->value, self::UNAVAILABLE);
        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::FAILED)->execute();

        return $this->failWorkflow([
            'order' => $order->getId(),
            'message' => $e->getMessage(),
        ]);
    }

    private function skipped(Model $order, string $message): array
    {
        return [
            'order' => $order->getId(),
            'status' => 'skipped',
            'message' => $message,
        ];
    }

    private function failInvalid(Model $order, string $reason, ?string $detail = null): array
    {
        $order->set(InsuranceCustomFieldEnum::FAILURE_REASON->value, $reason);

        return $this->failReleasingHold($order, $detail ?? $reason);
    }
}

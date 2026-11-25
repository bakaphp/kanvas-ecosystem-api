<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Connectors\PasoRapido\Events\PasoRapidoRechargeProcessed;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\SendPaymentReceiptAction;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Jobs\RetryPaymentCaptureJob;
use Kanvas\Souk\Payments\Jobs\RetryPaymentReversalJob;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;

final class SettleAuthorizedPaymentAction
{
    public const array PROCESSORS = ['portal', 'azul'];

    private readonly Order $order;

    public function __construct(
        private readonly Payments $payment,
        private readonly ?PasoRapidoService $pasoRapidoService = null,
    ) {
        $this->order = $payment->payable;
    }

    public function execute(): array
    {
        if ($this->payment->fresh()->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return $this->recoveryResult('skipped', 'Payment is no longer AUTHORIZED');
        }

        $processor = ProcessorFactory::make(
            $this->payment->processorName(),
            $this->order->app,
            $this->payment->company
        );

        $confirmation = $this->confirmWithPasoRapido();

        if (! $this->shouldCapture($confirmation)) {
            return $this->release(
                $processor,
                (string) ($confirmation['message'] ?? 'Paso Rapido confirmation failed')
            );
        }

        $capture = $processor->capture($this->payment, $this->order);

        if ($capture->skipped) {
            return $this->recoveryResult('skipped', $capture->message);
        }

        if (! $capture->success) {
            return $this->holdForCaptureRecovery($capture);
        }

        SendPaymentReceiptAction::trySend($this->order, $this->payment);

        return [
            'status' => 'success',
            'message' => $confirmation['message'] ?? 'Payment captured',
            'data' => $confirmation['data'] ?? [],
        ];
    }

    public static function settles(Payments $payment): bool
    {
        return in_array($payment->processorName(), self::PROCESSORS, true);
    }

    private function confirmWithPasoRapido(): ?array
    {
        if ($this->order->orderType?->name !== OrderTypeEnum::PASO_RAPIDO->value) {
            return null;
        }

        $confirmation = new CreatePasoRapidoOrderAction(
            app: $this->order->app,
            order: $this->order,
            pasoRapidoService: $this->pasoRapidoService,
            bankTransaction: $this->payment->payment_intent_id,
        )->execute();

        $recharged = $this->rechargeConfirmed($confirmation);

        PasoRapidoRechargeProcessed::dispatch(
            $this->order->getId(),
            $this->order->uuid,
            $recharged ? PasoRapidoRechargeProcessed::SUCCESS : PasoRapidoRechargeProcessed::FAILED,
            (string) ($confirmation['message'] ?? '')
        );

        return $confirmation;
    }

    private function shouldCapture(?array $confirmation): bool
    {
        $confirmed = $confirmation === null || $this->rechargeConfirmed($confirmation);
        $flag = $this->order->fresh()->get(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value);

        $this->payment->addLog('capture_decision', [
            'order_id' => $this->order->id,
            'order_type' => $this->order->orderType?->name,
            'processor' => $this->payment->processorName(),
            'intent_id' => $this->payment->payment_intent_id,
            'paso_rapido_confirmed' => $confirmed,
            'should_capture' => $flag,
            'will_capture' => $confirmed && $this->captureAllowed($flag),
        ]);

        return $confirmed && $this->captureAllowed($flag);
    }

    private function rechargeConfirmed(array $confirmation): bool
    {
        return ($confirmation['status'] ?? null) === 'success';
    }

    private function captureAllowed(mixed $flag): bool
    {
        return $flag === null || (bool) $flag;
    }

    private function holdForCaptureRecovery(CaptureResult $capture): array
    {
        $reason = 'Capture failed: ' . $capture->message;

        if ($capture->retryable) {
            RetryPaymentCaptureJob::schedule(
                $this->order->app,
                $this->payment,
                $reason,
                $capture->message
            );

            return $this->recoveryResult(RetryPaymentCaptureJob::PENDING_KEY, $capture->message);
        }

        RetryPaymentCaptureJob::flagForReview(
            $this->payment,
            $reason,
            $capture->message
        );

        return $this->recoveryResult(RetryPaymentCaptureJob::NEEDS_REVIEW_KEY, $capture->message);
    }

    private function recoveryResult(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'data' => ['order' => $this->order->getId(), 'payment' => $this->payment->getId()],
        ];
    }

    private function release(PaymentProcessorInterface $processor, string $reason): array
    {
        $this->payment->addLog('payment_reversal', [
            'order_id' => $this->order->id,
            'reason' => $reason,
        ]);

        $result = $processor->void($this->payment, $this->order, ['reason' => $reason]);

        if ($result->skipped) {
            return $this->recoveryResult('skipped', $result->message);
        }

        if (! $result->success) {
            RetryPaymentReversalJob::schedule(
                $this->order->app,
                $this->payment,
                $this->order->getTotalAmount(),
                $reason,
                $result->message
            );
        }

        return $this->recoveryResult(PaymentStatusEnum::FAILED->value, $result->message . ' - ' . $reason);
    }
}

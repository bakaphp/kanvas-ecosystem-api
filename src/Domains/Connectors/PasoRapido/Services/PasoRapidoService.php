<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\DataTransferObject\BillingDetail;
use Kanvas\Connectors\PasoRapido\DataTransferObject\CancelPaymentResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyPaymentResponse;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PasoRapidoService
{
    private const MINUTE_WINDOW_SECONDS = 60;
    private const DAILY_WINDOW_SECONDS = 86400;
    private const RECENT_TAGS_TTL_SECONDS = 600;

    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = [],
        ?Client $client = null
    ) {
        $this->client = $client ?? (new Client($app, $company, $config));
    }

    /**
     * Consult the details of the clients. This method will allow users of the
     * service to consult the details linked to a fast pass device number (TAG).
     */
    public function verifyCustomer(string $tag): VerifyCustomerResponse
    {
        $user = auth()->user();
        $userId = $user?->getId() ?? 0;
        $appId = $this->app->getId();

        $maxAttempts = (int) ($this->app->get(ConfigurationEnum::VERIFY_MAX_ATTEMPTS->value) ?? 3);
        $maxDaily = (int) ($this->app->get(ConfigurationEnum::VERIFY_MAX_DAILY->value) ?? 30);
        $sequentialThreshold = (int) ($this->app->get(ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD->value) ?? 5);

        $minuteKey = "paso-rapido-verify:{$appId}:{$userId}";
        $dailyKey = "paso-rapido-verify-daily:{$appId}:{$userId}";
        $recentTagsKey = "paso-rapido-verify-tags:{$appId}:{$userId}";

        // 1. Per-minute rate limit
        if (RateLimiter::tooManyAttempts($minuteKey, $maxAttempts)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido per-minute limit exceeded - user:{$userId} app:{$appId}"
            ));
            throw new TooManyRequestsHttpException(
                message: 'Too many tag verification requests. Please try again later.'
            );
        }

        // 2. Daily limit
        if (RateLimiter::tooManyAttempts($dailyKey, $maxDaily)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido daily limit exceeded - user:{$userId} app:{$appId} max:{$maxDaily}"
            ));
            throw new TooManyRequestsHttpException(
                message: 'Daily tag verification limit reached.'
            );
        }

        // 3. Sequential pattern detection
        $recentTags = Cache::get($recentTagsKey, []);
        $recentTags[] = $tag;
        $recentTags = array_slice($recentTags, -$sequentialThreshold);

        if (count($recentTags) >= $sequentialThreshold && $this->isSequentialPattern($recentTags)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido sequential scan detected - user:{$userId} app:{$appId} tags:" . implode(',', $recentTags)
            ));
            RateLimiter::hit($dailyKey, self::DAILY_WINDOW_SECONDS);
            Cache::forget($recentTagsKey);

            throw new TooManyRequestsHttpException(
                message: 'Suspicious activity detected. Access temporarily restricted.'
            );
        }

        Cache::put($recentTagsKey, $recentTags, self::RECENT_TAGS_TTL_SECONDS);
        RateLimiter::hit($minuteKey, self::MINUTE_WINDOW_SECONDS);
        RateLimiter::hit($dailyKey, self::DAILY_WINDOW_SECONDS);

        $this->logTagVerification($user, $tag);

        $response = $this->client->post(ConfigurationEnum::VERIFY_PATH->value . '?referencia=' . $tag, []);

        return VerifyCustomerResponse::from([
            'username' => $response['nombreUsuario'] ?? "",
            'lastname' => $response['apellidoUsuario'] ?? "",
            'device' => $response['dispositivo'],
            'message' => $response['descripcionMensaje'],
            'document' => $response['rnc_Cedula'],
            'balance' => $response['balance'],
            'type' => $response['tipoDeReferencia'],
            'reference' => $response['referencia'],
            'account' => $response['cuenta'],
            'status' => $response['estado'],
        ]);
    }

    public function confirmPayment(PaymentConfirmData $data): PaymentConfirmResponse
    {
        $response = $this->client->post(ConfigurationEnum::CONFIRM_PAYMENT_PATH->value, [
            'referencia' => $data->reference,
            'transaccionBanco' => $data->bankTransaction,
            'valorPagado' => $data->amount,
            'creditoFiscal' => $data->fiscalCredit,
            'rnc_Cedula' => $data->dni,
        ]);

        return PaymentConfirmResponse::from([
            'message' => $response['descripcionMensaje'],
            'amount' => $response['montoAcreditado'],
            'order' => $response['orden'],
            'tag' => $response['tag'],
            'account' => $response['cuenta'],
            'creditDate' => $response['fechaCredito'],
            'invoiceDetails' => InvoiceDetails::from([
                'commercialName' => $response['detallesFactura']['nombreComercial'] ?? '',
                'document' => $response['detallesFactura']['rncCedula'] ?? '',
                'fiscalCredit' => $response['detallesFactura']['valorFiscal'] ?? false,
                'invoice' => $response['detallesFactura']['comprobante'] ?? '',
                'pdf' => $response['detallesFactura']['pdf'] ?? '',
                'reference' => $response['detallesFactura']['referencia'] ?? '',
            ])
        ]);
    }

    public function verifyPayment(string $transactionNumber): VerifyPaymentResponse
    {
        $response = $this->client->get(ConfigurationEnum::VERIFY_PAYMENT_PATH->value . '?numeroTransaccion=' . $transactionNumber, []);

        return VerifyPaymentResponse::from([
            'availableToCancel' => $response['availableToCancel'],
            'exists' => $response['exists'],
            'applied' => $response['applied'],
            'description' => $response['description'],
            'billingDetail' => BillingDetail::from([
                'document' => $response['rncCedula'],
                'fiscalCredit' => $response['valorFiscal'],
                'invoice' => $response['comprobante'],
                'pdf' => $response['pdf'],
            ]),
        ]);
    }

    public function cancelPayment(string $transactionNumber): CancelPaymentResponse
    {
        $response = $this->client->post(ConfigurationEnum::CANCEL_PAYMENT_PATH->value . '?numeroTransaccion=' . $transactionNumber, []);

        return CancelPaymentResponse::from($response);
    }

    private function getClientIp(): string
    {
        $request = request();

        return $request->header('X-Forwarded-For')
            ? explode(',', $request->header('X-Forwarded-For'))[0]
            : $request->ip();
    }

    private function logTagVerification(UserInterface $user, string $tag): void
    {
        activity()
            ->causedBy($user)
            ->withProperties([
                'tag' => $tag,
                'app_id' => $this->app->getId(),
                'ip' => $this->getClientIp(),
            ])
            ->log('PasoRapido tag verification');
    }

    /**
     * Detect sequential tag scanning (e.g., 941637, 941638, 941639...).
     * Returns true if the tags form an ascending sequence with delta <= 2.
     */
    private function isSequentialPattern(array $tags): bool
    {
        $numeric = array_filter($tags, 'is_numeric');

        if (count($numeric) !== count($tags)) {
            return false;
        }

        $values = array_map('intval', array_values($numeric));
        $sequentialSteps = 0;

        for ($i = 1; $i < count($values); $i++) {
            $delta = abs($values[$i] - $values[$i - 1]);
            if ($delta > 0 && $delta <= 2) {
                $sequentialSteps++;
            }
        }

        return $sequentialSteps >= count($values) - 1;
    }
}

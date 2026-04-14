<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\IPInfo;
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
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
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

        $clientIp = IPInfo::getClientIp();

        if ($this->app->get(ConfigurationEnum::VERIFY_REQUIRE_VERIFIED_ACCOUNT->value) && $user && ! $user->getAppIsVerified()) {
            $email = $user->email ?? 'unknown';
            report(new ValidationException("PasoRapido unverified account attempt - user:{$userId} email:{$email} ip:{$clientIp} app:{$appId} tag:{$tag}"));

            throw new ValidationException('Account not verified.');
        }

        $tagAttributeSlug = $this->app->get(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG->value);

        if ($tagAttributeSlug && ! ProductsRepository::existsByAttributeValue($this->app, $this->company, $tagAttributeSlug, $tag, $userId)) {
            $email = $user?->email ?? 'unknown';
            report(new ValidationException("PasoRapido unauthorized tag lookup - user:{$userId} email:{$email} ip:{$clientIp} app:{$appId} tag:{$tag}"));

            throw new ValidationException('Tag not associated with your account.');
        }

        $ipMaxUsers = (int) ($this->app->get(ConfigurationEnum::VERIFY_IP_MAX_USERS->value) ?? 5);
        $ipUsersKey = "paso-rapido-ip-users:{$appId}:{$clientIp}";
        $ipUsers = Cache::get($ipUsersKey, []);

        if (! in_array($userId, $ipUsers)) {
            $ipUsers[] = $userId;
            Cache::put($ipUsersKey, $ipUsers, self::DAILY_WINDOW_SECONDS);
        }

        if (count($ipUsers) > $ipMaxUsers) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido account farming detected - ip:{$clientIp} users:" . implode(',', $ipUsers) . " app:{$appId}"
            ));

            throw new TooManyRequestsHttpException(
                message: 'Suspicious activity detected. Access temporarily restricted.'
            );
        }

        $ipMaxDaily = (int) ($this->app->get(ConfigurationEnum::VERIFY_IP_MAX_DAILY->value) ?? 50);
        $ipDailyKey = "paso-rapido-verify-ip-daily:{$appId}:{$clientIp}";

        if (RateLimiter::tooManyAttempts($ipDailyKey, $ipMaxDaily)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido IP daily limit exceeded - ip:{$clientIp} app:{$appId}"
            ));

            throw new TooManyRequestsHttpException(
                message: 'Too many requests from this network. Please try again later.'
            );
        }

        $maxAttempts = (int) ($this->app->get(ConfigurationEnum::VERIFY_MAX_ATTEMPTS->value) ?? 3);
        $maxDaily = (int) ($this->app->get(ConfigurationEnum::VERIFY_MAX_DAILY->value) ?? 30);
        $sequentialThreshold = (int) ($this->app->get(ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD->value) ?? 5);

        $minuteKey = "paso-rapido-verify:{$appId}:{$userId}";
        $dailyKey = "paso-rapido-verify-daily:{$appId}:{$userId}";
        $recentTagsKey = "paso-rapido-verify-tags:{$appId}:{$userId}";

        if (RateLimiter::tooManyAttempts($minuteKey, $maxAttempts)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido per-minute limit exceeded - user:{$userId} app:{$appId}"
            ));

            throw new TooManyRequestsHttpException(
                message: 'Too many tag verification requests. Please try again later.'
            );
        }

        if (RateLimiter::tooManyAttempts($dailyKey, $maxDaily)) {
            report(new TooManyRequestsHttpException(
                message: "PasoRapido daily limit exceeded - user:{$userId} app:{$appId} max:{$maxDaily}"
            ));

            throw new TooManyRequestsHttpException(
                message: 'Daily tag verification limit reached.'
            );
        }

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
        RateLimiter::hit($ipDailyKey, self::DAILY_WINDOW_SECONDS);

        $this->logTagVerification($user, $tag);

        $response = $this->client->post(ConfigurationEnum::VERIFY_PATH->value . '?referencia=' . $tag, []);

        return VerifyCustomerResponse::from([
            'username' => $response['nombreUsuario'] ?? '',
            'lastname' => $response['apellidoUsuario'] ?? '',
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
            ]),
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

    private function logTagVerification(UserInterface $user, string $tag): void
    {
        activity()
            ->causedBy($user)
            ->withProperties([
                'tag' => $tag,
                'app_id' => $this->app->getId(),
                'ip' => IPInfo::getClientIp(),
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

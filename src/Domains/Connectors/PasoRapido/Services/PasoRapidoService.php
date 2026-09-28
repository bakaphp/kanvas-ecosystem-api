<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\IPInfo;
use Baka\Users\Contracts\UserInterface;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\DataTransferObject\BillingDetail;
use Kanvas\Connectors\PasoRapido\DataTransferObject\CancelPaymentResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyPaymentResponse;
use Kanvas\Connectors\PasoRapido\Enums\CompanySettingsEnum;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PasoRapidoService
{
    private const int MINUTE_WINDOW_SECONDS = 60;
    private const int DAILY_WINDOW_SECONDS = 86400;
    private const int RECENT_TAGS_TTL_SECONDS = 600;
    public const int VERIFY_ERROR_BLOCK_THRESHOLD = 20;
    private const int VERIFY_ERROR_BLOCK_WINDOW_SECONDS = 300;
    private const int VERIFY_ERROR_BLOCK_TTL_SECONDS = 3600;

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

        $this->assertIpNotAutoBlocked($clientIp);

        if ($this->app->get(ConfigurationEnum::VERIFY_REQUIRE_VERIFIED_ACCOUNT->value) && $user && ! $user->getAppIsVerified()) {
            $email = $user->email ?? 'unknown';
            report(new ValidationException("PasoRapido unverified account attempt - user:{$userId} email:{$email} ip:{$clientIp} app:{$appId} tag:{$tag}"));

            throw new ValidationException('Account not verified.');
        }

        $corporateCompany = $this->resolveCorporateCompany($user);

        $this->assertCompanyNotBlocked($this->company);

        // A corporate user's current company is not necessarily the corporate one,
        // so a block on either side has to stop the request.
        if ($corporateCompany && $corporateCompany->getId() !== $this->company->getId()) {
            $this->assertCompanyNotBlocked($corporateCompany);
        }

        $tagAttributeSlug = $this->app->get(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG->value);

        if ($tagAttributeSlug && ! $this->userCanAccessTag($user, $tagAttributeSlug, $tag)) {
            $email = $user?->email ?? 'unknown';
            report(new ValidationException("PasoRapido unauthorized tag lookup - user:{$userId} email:{$email} ip:{$clientIp} app:{$appId} tag:{$tag}"));

            throw new ValidationException('Tag not associated with your account.');
        }

        $isCorporate = $corporateCompany !== null;
        $limits = $this->resolveLimits($isCorporate, $corporateCompany ?? $this->company);
        $context = "user:{$userId} app:{$appId} corporate:" . ($isCorporate ? '1' : '0');

        $minuteKey = "paso-rapido-verify:{$appId}:{$userId}";
        $dailyKey = "paso-rapido-verify-daily:{$appId}:{$userId}";
        $recentTagsKey = "paso-rapido-verify-tags:{$appId}:{$userId}";
        $ipDailyKey = "paso-rapido-verify-ip-daily:{$appId}:{$clientIp}";

        if ($limits['ipUsers'] > 0) {
            $ipUsersKey = "paso-rapido-ip-users:{$appId}:{$clientIp}";
            $ipUsers = Cache::get($ipUsersKey, []);

            if (! in_array($userId, $ipUsers)) {
                $ipUsers[] = $userId;
                Cache::put($ipUsersKey, $ipUsers, self::DAILY_WINDOW_SECONDS);
            }

            if (count($ipUsers) > $limits['ipUsers']) {
                report(new TooManyRequestsHttpException(
                    message: "PasoRapido account farming detected - ip:{$clientIp} users:" . implode(',', $ipUsers) . " {$context}"
                ));

                throw new TooManyRequestsHttpException(
                    message: 'Suspicious activity detected. Access temporarily restricted.'
                );
            }
        }

        if ($limits['ipDaily'] > 0) {
            if (RateLimiter::tooManyAttempts($ipDailyKey, $limits['ipDaily'])) {
                report(new TooManyRequestsHttpException(
                    message: "PasoRapido IP daily limit exceeded - ip:{$clientIp} {$context}"
                ));

                throw new TooManyRequestsHttpException(
                    message: 'Too many requests from this network. Please try again later.'
                );
            }

            RateLimiter::hit($ipDailyKey, self::DAILY_WINDOW_SECONDS);
        }

        if ($limits['daily'] > 0) {
            if (RateLimiter::tooManyAttempts($dailyKey, $limits['daily'])) {
                report(new TooManyRequestsHttpException(
                    message: "PasoRapido daily limit exceeded - {$context} max:{$limits['daily']}"
                ));

                throw new TooManyRequestsHttpException(
                    message: 'Daily tag verification limit reached.'
                );
            }

            RateLimiter::hit($dailyKey, self::DAILY_WINDOW_SECONDS);
        }

        if ($limits['sequential'] > 0) {
            $recentTags = Cache::get($recentTagsKey, []);
            $recentTags[] = $tag;
            $recentTags = array_slice($recentTags, -$limits['sequential']);

            if (count($recentTags) >= $limits['sequential'] && $this->isSequentialPattern($recentTags)) {
                report(new TooManyRequestsHttpException(
                    message: "PasoRapido sequential scan detected - {$context} tags:" . implode(',', $recentTags)
                ));
                RateLimiter::hit($dailyKey, self::DAILY_WINDOW_SECONDS);
                Cache::forget($recentTagsKey);

                throw new TooManyRequestsHttpException(
                    message: 'Suspicious activity detected. Access temporarily restricted.'
                );
            }

            Cache::put($recentTagsKey, $recentTags, self::RECENT_TAGS_TTL_SECONDS);
        }

        if ($limits['minute'] > 0) {
            if (RateLimiter::tooManyAttempts($minuteKey, $limits['minute'])) {
                report(new TooManyRequestsHttpException(
                    message: "PasoRapido per-minute limit exceeded - {$context}"
                ));

                throw new TooManyRequestsHttpException(
                    message: 'Too many tag verification requests. Please try again later.'
                );
            }

            RateLimiter::hit($minuteKey, self::MINUTE_WINDOW_SECONDS);
        }

        $this->logTagVerification($user, $tag);

        try {
            $response = $this->client->post(ConfigurationEnum::VERIFY_PATH->value . '?referencia=' . $tag, []);
        } catch (ClientException $e) {
            $this->handleVerifyClientException($e, $tag, $context);
        }

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

    private function handleVerifyClientException(ClientException $e, string $tag, string $context): never
    {
        $response = $e->getResponse();
        $statusCode = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $apiMessage = is_array($decoded) && is_string($decoded['descripcionMensaje'] ?? null)
            ? $decoded['descripcionMensaje']
            : null;

        $clientIp = IPInfo::getClientIp();

        $this->registerFailedLookup(
            $statusCode,
            $clientIp,
            $context
        );

        Log::warning('PasoRapido tag verification failed', [
            'status' => $statusCode,
            'message' => $apiMessage,
            'tag' => $tag,
            'ip' => $clientIp,
            'user_id' => auth()->id() ?? 0,
            'context' => $context,
        ]);

        throw new ValidationException($apiMessage ?: 'Unable to verify the tag at this time. Please try again later.');
    }

    /**
     * Reject requests from an IP that has been auto-blocked for repeated failed
     * lookups — checked before any DB/API work so a probing IP is cut off at the door.
     */
    private function assertIpNotAutoBlocked(string $clientIp): void
    {
        if (! Cache::get($this->autoBlockKey($clientIp))) {
            return;
        }

        throw new TooManyRequestsHttpException(
            message: 'Suspicious activity detected. Access temporarily restricted.'
        );
    }

    /**
     * Count a failed upstream lookup against the IP and auto-block it once the
     * threshold is crossed. 401 is our own auth problem (the client retries it),
     * and 5xx is the upstream being down — neither counts as tag probing.
     */
    private function registerFailedLookup(int $statusCode, string $clientIp, string $context): void
    {
        if ($statusCode === 401 || $statusCode < 400 || $statusCode >= 500) {
            return;
        }

        $failKey = "paso-rapido-verify-fail:{$this->app->getId()}:{$clientIp}";
        RateLimiter::hit($failKey, self::VERIFY_ERROR_BLOCK_WINDOW_SECONDS);

        if (RateLimiter::attempts($failKey) < self::VERIFY_ERROR_BLOCK_THRESHOLD) {
            return;
        }

        $blockKey = $this->autoBlockKey($clientIp);

        // Set (and report) the block once — re-reporting every blocked attempt would
        // re-flood Sentry, the very thing this whole path exists to prevent.
        if (! Cache::get($blockKey)) {
            Cache::put($blockKey, true, self::VERIFY_ERROR_BLOCK_TTL_SECONDS);
            report(new TooManyRequestsHttpException(
                message: "PasoRapido auto-blocked IP after repeated failed lookups - ip:{$clientIp} {$context}"
            ));
        }
    }

    private function autoBlockKey(string $clientIp): string
    {
        return "paso-rapido-verify-blocked-ip:{$this->app->getId()}:{$clientIp}";
    }

    /**
     * Internal balance lookup for post-payment sync jobs.
     * Bypasses the end-user rate limits / fraud heuristics applied by verifyCustomer().
     */
    public function fetchTagBalance(string $tag): VerifyCustomerResponse
    {
        $response = $this->client->post(ConfigurationEnum::VERIFY_PATH->value . '?referencia=' . $tag, []);

        return VerifyCustomerResponse::from([
            'username' => $response['nombreUsuario'] ?? '',
            'lastname' => $response['apellidoUsuario'] ?? '',
            'device' => $response['dispositivo'],
            'message' => $response['descripcionMensaje'],
            'document' => $response['rnc_Cedula'] ?? null,
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
            // PasoRapido validates valorPagado as .NET Int32. round() avoids losing
            // a peso on 500.99 → 500; spec works in whole DOP, no cents.
            'valorPagado' => (int) round($data->amount),
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

    /**
     * Allow tag access when EITHER the user owns it personally OR the tag belongs
     * to a corporate company (is_corporate=1) the user is associated with.
     */
    private function userCanAccessTag(?UserInterface $user, string $tagAttributeSlug, string $tag): bool
    {
        $userId = $user?->getId() ?? 0;

        if (ProductsRepository::existsByAttributeValue($this->app, $this->company, $tagAttributeSlug, $tag, $userId)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        $corporateCompanyIds = $this->onlyCorporate($user->companies())->pluck('companies.id');

        if ($corporateCompanyIds->isEmpty()) {
            return false;
        }

        return ProductsRepository::existsByAttributeValueInCompanies(
            $this->app,
            $corporateCompanyIds,
            $tagAttributeSlug,
            $tag,
        );
    }

    /**
     * The company whose corporate limits apply to this request. Prefers the current
     * company, falling back to any corporate company the user belongs to — a corporate
     * employee can be operating under a different current company.
     */
    private function resolveCorporateCompany(?UserInterface $user): ?CompanyInterface
    {
        if ($this->companyHasCorporateFlag($this->company->getId())) {
            return $this->company;
        }

        if (! $user) {
            return null;
        }

        return $this->onlyCorporate($user->companies())->first();
    }

    private function onlyCorporate(Relation $companies): Relation
    {
        return $companies->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('companies_settings')
                ->whereColumn('companies_settings.companies_id', 'companies.id')
                ->where('companies_settings.name', 'is_corporate')
                ->where('companies_settings.value', '1')
                ->where(function ($q) {
                    $q->where('companies_settings.is_deleted', 0)
                        ->orWhereNull('companies_settings.is_deleted');
                });
        });
    }

    private function assertCompanyNotBlocked(CompanyInterface $company): void
    {
        if (! filter_var($company->get(CompanySettingsEnum::VERIFY_BLOCKED->value), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $companyId = $company->getId();
        $userId = auth()->id() ?? 0;
        report(new ValidationException(
            "PasoRapido company blocked - company:{$companyId} user:{$userId} app:{$this->app->getId()}"
        ));

        throw new ValidationException(
            (string) ($company->get(CompanySettingsEnum::VERIFY_BLOCKED_REASON->value)
                ?: 'Tag verification is disabled for this company. Please contact support.')
        );
    }

    /**
     * Effective caps for this request. Corporate companies run the same checks as
     * retail, only against their own app-level defaults; a company setting overrides
     * either. A resolved 0 disables that check.
     *
     * Sequential-scan detection defaults to off for corporate: fleets buy TAGs in
     * blocks, so their legitimate lookups look exactly like a scan.
     *
     * @return array{minute: int, daily: int, ipDaily: int, ipUsers: int, sequential: int}
     */
    private function resolveLimits(bool $isCorporate, CompanyInterface $company): array
    {
        return [
            'minute' => $this->resolveLimit(
                $company,
                CompanySettingsEnum::VERIFY_MAX_ATTEMPTS,
                $isCorporate ? ConfigurationEnum::VERIFY_MAX_ATTEMPTS_CORPORATE : ConfigurationEnum::VERIFY_MAX_ATTEMPTS,
                $isCorporate ? 5 : 3
            ),
            'daily' => $this->resolveLimit(
                $company,
                CompanySettingsEnum::VERIFY_MAX_DAILY,
                $isCorporate ? ConfigurationEnum::VERIFY_MAX_DAILY_CORPORATE : ConfigurationEnum::VERIFY_MAX_DAILY,
                $isCorporate ? 30 : 30
            ),
            'ipDaily' => $this->resolveLimit(
                $company,
                CompanySettingsEnum::VERIFY_IP_MAX_DAILY,
                $isCorporate ? ConfigurationEnum::VERIFY_IP_MAX_DAILY_CORPORATE : ConfigurationEnum::VERIFY_IP_MAX_DAILY,
                $isCorporate ? 50 : 50
            ),
            'ipUsers' => $this->resolveLimit(
                $company,
                CompanySettingsEnum::VERIFY_IP_MAX_USERS,
                $isCorporate ? ConfigurationEnum::VERIFY_IP_MAX_USERS_CORPORATE : ConfigurationEnum::VERIFY_IP_MAX_USERS,
                $isCorporate ? 5 : 5
            ),
            'sequential' => $this->resolveLimit(
                $company,
                CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD,
                $isCorporate ? ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD_CORPORATE : ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD,
                $isCorporate ? 0 : 5
            ),
        ];
    }

    /**
     * Company setting wins over the app setting, which wins over the default.
     * Non-numeric values are skipped rather than cast — a typo'd setting would
     * otherwise become 0 and silently disable the check.
     */
    private function resolveLimit(
        CompanyInterface $company,
        CompanySettingsEnum $companySetting,
        ConfigurationEnum $appSetting,
        int $default
    ): int {
        $candidates = [
            $company->get($companySetting->value),
            $this->app->get($appSetting->value),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return $default;
    }

    private function companyHasCorporateFlag(int $companyId): bool
    {
        return CompaniesSettings::query()
            ->where('companies_id', $companyId)
            ->where('name', 'is_corporate')
            ->where('value', '1')
            ->where(function ($q) {
                $q->where('is_deleted', 0)->orWhereNull('is_deleted');
            })
            ->exists();
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

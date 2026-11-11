<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\Carbon;
use Kanvas\Connectors\VAuto\Enums\ConfigurationEnum;
use Kanvas\Connectors\VAuto\Exceptions\VAutoException;
use SoapClient;
use SoapFault;
use SoapHeader;

/**
 * vAuto Appraisal Service (SOAP 3.2). Every call carries an `Identity` header signed per request:
 * HMAC-SHA1 over `accessId . accessStamp` with the dealer's secret, base64-encoded.
 */
class Client
{
    public const string DEFAULT_WSDL = 'https://www2.vauto.com/Va.Ws/v3_2/appraisalservice.svc?singlewsdl';
    public const string SOAP_NAMESPACE = 'http://vauto.com/webservices/3.2/';

    /**
     * The API user vAuto issued to the Sales Assist integration; dealers do not get their own.
     */
    public const string DEFAULT_USER_NAME = 'DealerAppCenter';

    protected string $accessId;
    protected string $secretKey;
    protected string $entityId;

    public function __construct(
        protected CompanyInterface $company,
        protected ?SoapClient $soapClient = null,
    ) {
        $credentials = self::credentials($company)
            ?? throw new VAutoException('vAuto access id, secret key and entity id are required');

        [$this->accessId, $this->secretKey, $this->entityId] = $credentials;
    }

    public static function isConfigured(CompanyInterface $company): bool
    {
        return self::credentials($company) !== null;
    }

    public static function sign(string $accessId, string $secretKey, string $accessStamp): string
    {
        return base64_encode(hash_hmac('sha1', $accessId . $accessStamp, $secretKey, true));
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function userName(): string
    {
        return Str::trimToNull((string) $this->company->get(ConfigurationEnum::USER_NAME->value)) ?? self::DEFAULT_USER_NAME;
    }

    public function createAppraisal(array $request): object
    {
        $client = $this->soapClient();
        $client->__setSoapHeaders([$this->identityHeader()]);

        try {
            return $client->__soapCall('CreateAppraisal', [
                'CreateAppraisal' => ['request' => $request],
            ]);
        } catch (SoapFault $e) {
            throw new VAutoException('vAuto CreateAppraisal failed: ' . $e->getMessage());
        }
    }

    public function identityHeader(?Carbon $at = null): SoapHeader
    {
        $accessStamp = ($at ?? Carbon::now())->format('Y-m-d\TH:i:s.uP');

        return new SoapHeader(self::SOAP_NAMESPACE, 'Identity', [
            'AccessId' => $this->accessId,
            'AccessStamp' => $accessStamp,
            'Signature' => self::sign($this->accessId, $this->secretKey, $accessStamp),
        ]);
    }

    /**
     * @return array{string, string, string}|null access id, secret key, entity id; null when any is unset
     */
    protected static function credentials(CompanyInterface $company): ?array
    {
        $values = [];

        foreach ([ConfigurationEnum::ACCESS_ID, ConfigurationEnum::SECRET_KEY, ConfigurationEnum::ENTITY_ID] as $key) {
            $value = Str::trimToNull((string) $company->get($key->value));

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    protected function soapClient(): SoapClient
    {
        if ($this->soapClient !== null) {
            return $this->soapClient;
        }

        $wsdl = Str::trimToNull((string) $this->company->get(ConfigurationEnum::WSDL_URL->value)) ?? self::DEFAULT_WSDL;

        try {
            return $this->soapClient = new SoapClient($wsdl, [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
            ]);
        } catch (SoapFault $e) {
            throw new VAutoException('Failed to initialize vAuto SOAP client: ' . $e->getMessage());
        }
    }
}

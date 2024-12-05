<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use RuntimeException;
use SimpleXMLElement;

class Client
{
    protected string $apiBaseUrl = 'https://www.700Dealer.com/XCRS/Service.aspx'; // Production URL
    protected GuzzleClient $httpClient;
    protected string $account;
    protected string $password;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->account = $this->app->get(ConfigurationEnum::ACCOUNT->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);

        if (empty($this->account) || empty($this->password)) {
            throw new ValidationException('700Credit credentials are not set for ' . $this->app->name);
        }

        $this->httpClient = new GuzzleClient([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        throw new RuntimeException('GET method is not applicable for 700Credit integration.');
    }

    public function post(string $path, array $data = []): SimpleXMLElement
    {
        $requestData = array_merge($data, [
            'ACCOUNT' => $this->account,
            'PASSWD' => $this->password,
            'PRODUCT' => 'CREDIT',
            'BUREAU' => 'XPN', // Choose from XPN, TU, or EFX
            'PASS' => '2',
            'PROCESS' => 'PCCREDIT',
        ]);

        $response = $this->httpClient->post($this->apiBaseUrl, [
            'form_params' => $requestData,
        ]);

        $responseBody = $response->getBody()->getContents();

        // Process XML response
        return new SimpleXMLElement($responseBody);
    }
}

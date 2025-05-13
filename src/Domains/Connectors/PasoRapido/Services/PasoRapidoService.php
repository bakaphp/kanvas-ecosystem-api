<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\DataTransferObject\CancelPaymentResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyPaymentResponse;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;

class PasoRapidoService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->client = (new Client($app, $company, $config));
    }

    /**
     * Consult the details of the clients. This method will allow users of the
     * service to consult the details linked to a fast pass device number (TAG).
     */
    public function verifyCustomer(string $tag): VerifyCustomerResponse
    {
        $response = $this->client->post(ConfigurationEnum::VERIFY_PATH->value . "?referencia=" . $tag, []);

        return VerifyCustomerResponse::fromArray($response);
    }


    public function confirmPayment(PaymentConfirmData $data)
    {
        $response = $this->client->post(ConfigurationEnum::CONFIRM_PAYMENT_PATH->value, [
            'json' => [
                'referencia' => $data->reference,
                'transaccionBanco' => $data->bankTransaction,
                'valorPagado' => $data->amount,
                'creditoFiscal' => $data->fiscalCredit,
                'rnc_Cedula' => $data->dni
            ]
        ]);

        return PaymentConfirmResponse::fromArray($response);
    }

    public function verifyPayment(string $transactionNumber)
    {
        $response = $this->client->post(ConfigurationEnum::VERIFY_PAYMENT_PATH->value . "?numeroTransaccion=" . $transactionNumber, []);

        return VerifyPaymentResponse::fromArray($response);
    }

    public function cancelPayment(string $transactionNumber)
    {
        $response = $this->client->post(ConfigurationEnum::CANCEL_PAYMENT_PATH->value . "?numeroTransaccion=" . $transactionNumber, []);

        return CancelPaymentResponse::fromArray($response);
    }


}

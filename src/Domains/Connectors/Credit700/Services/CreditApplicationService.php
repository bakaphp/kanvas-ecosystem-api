<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplication;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class CreditApplicationService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * @return array{success: bool, transaction_id: string|null, token: string|null, response: array<string, mixed>}
     */
    public function submitToRouteOne(CreditApplication $application): array
    {
        $appOrCompany = $this->company ?? $this->app;

        try {
            $responseArray = $this->client->post(
                '/Request',
                $this->buildPayload($application, $appOrCompany)
            );

            $transactionId = $responseArray['XML_Report']['Transid'] ?? null;

            return [
                'success' => $transactionId !== null && ! isset($responseArray['Creditsystem_Error']),
                'transaction_id' => $transactionId,
                'token' => $responseArray['XML_Report']['Token'] ?? null,
                'response' => $responseArray,
            ];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to submit credit application to RouteOne: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('An error occurred submitting the credit application: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    protected function buildPayload(
        CreditApplication $application,
        AppInterface|CompanyInterface $appOrCompany
    ): array {
        $payload = [
            'ACCOUNT' => (string) $appOrCompany->get(ConfigurationEnum::ACCOUNT->value),
            'PASSWD' => (string) $appOrCompany->get(ConfigurationEnum::PASSWORD->value),
            'PRODUCT' => 'SAVEONLY',
            'PASS' => '2',
            'PROCESS' => 'PCCREDIT',
            'LOS_SYSTEM' => 'R1',

            'NAME' => $application->name,
            'SSN' => $application->ssn,
            'ADDRESS' => $application->address,
            'CITY' => $application->city,
            'STATE' => $application->state,
            'ZIP' => $application->zip,
        ];

        $optional = [
            'DOB' => $application->dob,
            'EMAIL' => $application->email,
            'PHONE' => $application->phone,
            'MOBILE' => $application->mobileNumber,

            'EMPLOYER' => $application->employer,
            'EMPLOYMENTSTATUS' => $application->employmentStatus,
            'POSITION' => $application->position,
            'YEARS' => $application->employmentYears,
            'MONTHS' => $application->employmentMonths,
            'WPHONE' => $application->workPhone,

            'PREVEMPLOYER' => $application->previousEmployer,
            'PREVEMPLOYMENTYEARS' => $application->previousEmploymentYears,
            'PREVOCCUPATION' => $application->previousEmploymentTitle,
            'PREVWORKPHONE' => $application->previousWorkPhone,

            'MINCOME' => $application->monthlyIncome,
            'OTHERINCOME' => $application->otherIncome,
            'OTHERINCOMEEXPLN' => $application->otherIncomeExplanation,

            'HOUSING' => $application->housingType,
            'HOUSINGPAY' => $application->housingPayment,

            'CURRENTADDRESSPERIOD' => $application->currentAddressPeriod,
            'PREVADDRESS' => $application->previousAddress,
            'PREVCITY' => $application->previousCity,
            'PREVSTATE' => $application->previousState,
            'PREVZIP' => $application->previousZip,
            'PREVADDRESSPERIOD' => $application->previousAddressPeriod,

            'DRIVERSLICENSENO' => $application->driversLicenseNumber,
            'DLSTATE' => $application->driversLicenseState,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null && $value !== '') {
                $payload[$key] = (string) $value;
            }
        }

        return $payload;
    }
}

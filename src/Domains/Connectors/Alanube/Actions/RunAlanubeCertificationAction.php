<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * DGII certification step 1: Alanube generates and sends the DGII test set (`POST /set-tests`) built around one
 * representative item, and we track it by id. A set already in progress is answered with HTTP 400 AP4001;
 * `retryNumber` (0–99) regenerates a set that failed.
 *
 * The other step, signing the certification documents DGII hands the taxpayer, is
 * SignAlanubeCertificationDocumentAction.
 *
 * @phpstan-type ItemExample array{billingIndicator: int, itemName: string, goodServiceIndicator: int, unitPriceItem: int, itemDescription?: string}
 */
class RunAlanubeCertificationAction
{
    /**
     * @param ItemExample $itemExample
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly array $itemExample,
        private readonly ?int $retryNumber = null,
    ) {
    }

    /**
     * @return array<array-key, mixed> the set test as Alanube answered (id, status, ...)
     */
    public function execute(): array
    {
        $client = new Client($this->app, $this->company);

        $body = ['itemExample' => $this->itemExample];

        if ($client->mode === AccountModeEnum::KANVAS) {
            $body['idCompany'] = $client->alanubeCompanyId()
                ?? throw new ValidationException('Onboard the company at Alanube before running certification.');
        }

        if ($this->retryNumber !== null) {
            $body['retryNumber'] = $this->retryNumber;
        }

        $setTest = $client->post('set-tests', $body);

        if (! empty($setTest['id'])) {
            $this->company->set(ConfigurationEnum::SET_TEST_ID->value, (string) $setTest['id']);
        }

        return $setTest;
    }

    /**
     * @return array<array-key, mixed>|null null when no set test was started or Alanube no longer has it
     */
    public static function status(Apps $app, Companies $company): ?array
    {
        $setTestId = (string) $company->get(ConfigurationEnum::SET_TEST_ID->value);

        if ($setTestId === '') {
            return null;
        }

        $client = new Client($app, $company);

        return $client->get($client->scopedPath('check-set-tests/' . $setTestId));
    }
}

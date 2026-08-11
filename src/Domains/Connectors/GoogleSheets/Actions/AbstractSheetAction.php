<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Sheets as GoogleSheetsService;
use Kanvas\Connectors\GoogleSheets\Client;

/** Shared constructor + lazy authenticated-service resolution for every Sheets read/write action. */
abstract class AbstractSheetAction
{
    public function __construct(
        protected AppInterface $app,
        protected string $spreadsheetId,
        protected ?string $range = null,
        protected ?GoogleSheetsService $service = null,
    ) {
    }

    protected function service(): GoogleSheetsService
    {
        return $this->service ??= Client::getInstance($this->app);
    }
}

<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Throwable;

/**
 * The accounting inbox's branch for a PDF the classifier called a bank statement. The account is matched
 * from the statement header (last 4 + currency), since an email carries no account id. The log row
 * records the outcome the same way a bill or expense does: linked to the statement when it posted,
 * failed with the reason otherwise.
 */
class IngestBankStatementFromInboxAction
{
    public function __construct(
        public readonly PdfIngestLog $log,
        public readonly PdfIngestInput $input,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): void
    {
        try {
            $result = new IngestBankStatementFileAction(
                app: $this->input->app,
                company: $this->input->company,
                file: $this->input->pdf,
                user: $this->user,
            )->execute();
        } catch (Throwable $e) {
            report($e);
            $this->log->status = PdfIngestStatusEnum::FAILED;
            $this->log->rejected_reason = 'Bank statement extraction failed: ' . $e->getMessage();

            return;
        }

        if ($result->statement !== null) {
            $this->log->linked_entity_type = 'bank_statement';
            $this->log->linked_entity_id = $result->statement->getId();
        }

        if (in_array($result->outcome, [BankStatementIngestOutcomeEnum::POSTED, BankStatementIngestOutcomeEnum::DUPLICATE], true)) {
            $this->log->status = PdfIngestStatusEnum::ENTITY_CREATED;

            return;
        }

        $this->log->status = PdfIngestStatusEnum::FAILED;
        $this->log->rejected_reason = 'Bank statement not posted: ' . $result->reason;
    }
}

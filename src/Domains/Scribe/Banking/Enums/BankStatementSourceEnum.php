<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

/**
 * How the statement reached us. The transactions it lands all carry the single source
 * `BankStatement::TRANSACTION_SOURCE` instead, so an overlapping CSV and PDF of the same month share
 * one dedupe key space.
 */
enum BankStatementSourceEnum: string
{
    case CSV = 'statement_csv';
    case PDF = 'statement_pdf';
    case WEBHOOK = 'statement_webhook';
}

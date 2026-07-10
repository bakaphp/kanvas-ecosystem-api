<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportAccount;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Tests\TestCase;

class AcumaticaImportAccountTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AccountID' => '10500',
            'AccountCD' => '1200',
            'Description' => 'Accounts Receivable',
            'Type' => 'Asset',
        ], $overrides);
    }

    public function testMapsCoreFields(): void
    {
        $account = AcumaticaImportAccount::fromRow($this->row());

        $this->assertSame('10500', $account->externalId);
        $this->assertSame('1200', $account->accountNumber);
        $this->assertSame('Accounts Receivable', $account->name);
        $this->assertSame('Accounts Receivable', $account->description);
        $this->assertSame(AccountTypeEnum::ASSET, $account->accountType);
    }

    public function testTypeMapping(): void
    {
        $this->assertSame(AccountTypeEnum::ASSET, AcumaticaImportAccount::fromRow($this->row(['Type' => 'Asset']))->accountType);
        $this->assertSame(AccountTypeEnum::LIABILITY, AcumaticaImportAccount::fromRow($this->row(['Type' => 'Liability']))->accountType);
        $this->assertSame(AccountTypeEnum::REVENUE, AcumaticaImportAccount::fromRow($this->row(['Type' => 'Income']))->accountType);
        $this->assertSame(AccountTypeEnum::EXPENSE, AcumaticaImportAccount::fromRow($this->row(['Type' => 'Expense']))->accountType);
    }

    public function testSingleLetterTypeMapping(): void
    {
        $this->assertSame(AccountTypeEnum::ASSET, AcumaticaImportAccount::fromRow($this->row(['Type' => 'A']))->accountType);
        $this->assertSame(AccountTypeEnum::EXPENSE, AcumaticaImportAccount::fromRow($this->row(['Type' => 'E']))->accountType);
    }

    public function testUnknownTypeIsNull(): void
    {
        $this->assertNull(AcumaticaImportAccount::fromRow($this->row(['Type' => '']))->accountType);
        $this->assertNull(AcumaticaImportAccount::fromRow($this->row(['Type' => 'Zebra']))->accountType);
    }

    public function testNameFallsBackToAccountNumberWhenNoDescription(): void
    {
        $account = AcumaticaImportAccount::fromRow($this->row(['Description' => '']));

        $this->assertSame('1200', $account->name);
        $this->assertNull($account->description);
    }
}

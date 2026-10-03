<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Services;

use Kanvas\Intelligence\FollowUp\Services\FollowUpKnowledgeRetriever;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use Tests\TestCase;

class FollowUpKnowledgeRetrieverTest extends TestCase
{
    public function testRetrievesTheMatchingEmailDayFromAnOdsWorkbook(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('SMS Follow-up');
        $emailSheet = $spreadsheet->createSheet()->setTitle('Email Follow-up');
        $emailSheet->setCellValue('B3', 'Day');
        $emailSheet->setCellValue('C3', 'Subject');
        $emailSheet->setCellValue('D3', 'Message');
        $emailSheet->setCellValue('B4', 4);
        $emailSheet->setCellValue('C4', 'Still considering your options?');
        $emailSheet->setCellValue('D4', 'Reply with the option you prefer.');

        $path = tempnam(sys_get_temp_dir(), 'follow-up-test-');
        $this->assertNotFalse($path);

        try {
            new Ods($spreadsheet)->save($path);

            $result = new FollowUpKnowledgeRetriever()->retrieveFromSpreadsheet(
                $path,
                'Day 4',
                'email',
                'campaign.ods',
            );

            $this->assertNotNull($result);
            $this->assertSame('campaign.ods', $result['source']);
            $this->assertSame('Email Follow-up', $result['sheet']);
            $this->assertSame(4, $result['row']);
            $this->assertStringContainsString('Subject guidance: Still considering your options?', $result['content']);
            $this->assertStringContainsString('Message guidance: Reply with the option you prefer.', $result['content']);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
}

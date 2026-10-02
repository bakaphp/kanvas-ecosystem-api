<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Intelligence\Agents\Models\Agent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class FollowUpKnowledgeRetriever
{
    /**
     * @return array{source: string, sheet: string, row: int, content: string}|null
     */
    public function retrieve(Agent $agent, string $stageName, string $channelType): ?array
    {
        $attachment = $agent->getFileByName('follow_up_rag_source');
        $filesystem = $attachment?->filesystem;
        if ($filesystem === null) {
            return null;
        }

        $path = is_string($filesystem->path) && is_file($filesystem->path)
            ? $filesystem->path
            : null;
        $temporaryPath = null;

        try {
            if ($path === null && is_string($filesystem->url) && $filesystem->url !== '') {
                $temporaryPath = tempnam(sys_get_temp_dir(), 'follow-up-knowledge-');
                if ($temporaryPath === false) {
                    return null;
                }

                file_put_contents($temporaryPath, SafeUrlFetcher::fetch($filesystem->url));
                $path = $temporaryPath;
            }

            if ($path === null) {
                return null;
            }

            return $this->retrieveFromSpreadsheet(
                $path,
                $stageName,
                $channelType,
                (string) ($filesystem->name ?: basename($path)),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * Exact-key retrieval is intentional: the workbook is a small campaign
     * knowledge table keyed by follow-up day and outbound channel.
     *
     * @return array{source: string, sheet: string, row: int, content: string}|null
     */
    public function retrieveFromSpreadsheet(
        string $path,
        string $stageName,
        string $channelType,
        ?string $sourceName = null,
    ): ?array {
        if (! preg_match('/\bday\s+(\d+)\b/i', $stageName, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $sheetName = strtolower($channelType) === 'email'
            ? 'Email Follow-up'
            : 'SMS Follow-up';
        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                return null;
            }

            for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
                if ((int) $sheet->getCell("B{$row}")->getCalculatedValue() !== $day) {
                    continue;
                }

                $parts = [];
                if ($sheetName === 'Email Follow-up') {
                    $subject = trim((string) $sheet->getCell("C{$row}")->getCalculatedValue());
                    if ($subject !== '') {
                        $parts[] = 'Subject guidance: ' . $subject;
                    }
                    $messageColumn = 'D';
                } else {
                    $messageColumn = 'C';
                }

                $message = trim((string) $sheet->getCell("{$messageColumn}{$row}")->getCalculatedValue());
                if ($message !== '') {
                    $parts[] = 'Message guidance: ' . $message;
                }

                if ($parts === []) {
                    return null;
                }

                return [
                    'source' => $sourceName ?? basename($path),
                    'sheet' => $sheetName,
                    'row' => $row,
                    'content' => implode("\n", $parts),
                ];
            }

            return null;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}

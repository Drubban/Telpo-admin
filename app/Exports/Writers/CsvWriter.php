<?php

namespace App\Exports\Writers;

use App\Exports\ExportDataset;
use App\Support\Cell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvWriter
{
    public function respond(ExportDataset $dataset, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($dataset) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel abra bien los acentos.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $dataset->headings(), ',', '"', '');
            foreach ($dataset->rows() as $row) {
                fputcsv($out, Cell::safeRow($row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

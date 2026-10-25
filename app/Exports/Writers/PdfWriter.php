<?php

namespace App\Exports\Writers;

use App\Exports\ExportDataset;
use App\Support\BillingCycle;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

class PdfWriter
{
    public function respond(ExportDataset $dataset, string $filename)
    {
        $rows = [];
        foreach ($dataset->rows() as $row) {
            $rows[] = $row;
        }

        return Pdf::loadView('exports.table', [
            'title' => $dataset->title(),
            'description' => $dataset->description(),
            'headings' => $dataset->headings(),
            'rows' => $rows,
            'generatedAt' => CarbonImmutable::now(BillingCycle::timezone())->format('Y-m-d H:i'),
            'filename' => $filename,
        ])->setPaper('a4', 'landscape')->download($filename);
    }
}

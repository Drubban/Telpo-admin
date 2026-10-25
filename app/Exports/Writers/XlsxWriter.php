<?php

namespace App\Exports\Writers;

use App\Exports\ExportDataset;
use App\Support\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Excel moderno (.xlsx) escrito en streaming con openspout. */
class XlsxWriter
{
    public function respond(ExportDataset $dataset, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'ex');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($dataset->headings()));
        foreach ($dataset->rows() as $row) {
            $writer->addRow(Row::fromValues(Cell::safeRow($row)));
        }
        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}

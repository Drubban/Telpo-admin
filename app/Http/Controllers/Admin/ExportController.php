<?php

namespace App\Http\Controllers\Admin;

use App\Exports\DevicesDataset;
use App\Exports\EventsDataset;
use App\Exports\ExportDataset;
use App\Exports\PlanDataset;
use App\Exports\UsageDataset;
use App\Exports\Writers\CsvWriter;
use App\Exports\Writers\PdfWriter;
use App\Exports\Writers\XlsxWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportRequest;
use App\Models\AuditLog;

class ExportController extends Controller
{
    public function __invoke(ExportRequest $request)
    {
        $dataset = $this->dataset($request);
        $format = $request->validated('format');

        // El PDF se arma en memoria: con tablas enormes es mejor CSV o XLSX.
        $max = (int) config('telpo.pdf_max_rows', 5000);
        if ($format === 'pdf' && $dataset->count() > $max) {
            return response()->json([
                'message' => "Hay más de {$max} filas. Reduce el rango o exporta en CSV o Excel.",
            ], 422);
        }

        // Nombre fijo EX-T20-AAAA-MM-DD + extension.
        $filename = $request->fileBase().'.'.$format;

        AuditLog::record('export', $request->user()->id, $request->validated('dataset'), [
            'format' => $format,
            'scope' => $request->scope(),
            'date' => $request->referenceDate()->toDateString(),
        ], $request->ip());

        return match ($format) {
            'csv' => (new CsvWriter)->respond($dataset, $filename),
            'xlsx' => (new XlsxWriter)->respond($dataset, $filename),
            'pdf' => (new PdfWriter)->respond($dataset, $filename),
        };
    }

    private function dataset(ExportRequest $request): ExportDataset
    {
        $filters = $request->filters();

        return match ($request->validated('dataset')) {
            'devices' => new DevicesDataset($filters, $request->referenceDate()),
            'events' => new EventsDataset($filters, $request->range()),
            'usage' => new UsageDataset($filters, $request->range()),
            'plan' => new PlanDataset($filters, $request->referenceDate()),
        };
    }
}

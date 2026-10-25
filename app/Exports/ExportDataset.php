<?php

namespace App\Exports;

/** Una tabla exportable: titulo, encabezados y filas (se leen en streaming, no en memoria). */
interface ExportDataset
{
    public function title(): string;

    /** @return list<string> */
    public function headings(): array;

    /** @return iterable<int, list<scalar|null>> */
    public function rows(): iterable;

    public function count(): int;

    /** Resumen de los filtros aplicados, para el encabezado del PDF. */
    public function description(): string;
}

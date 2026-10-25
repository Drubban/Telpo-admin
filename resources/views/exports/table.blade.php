<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 24px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1f2933; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .meta { color: #52606d; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #123b4a; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; }
        td { padding: 3px 5px; border-bottom: 1px solid #d9e2ec; vertical-align: top; }
        tr:nth-child(even) td { background: #f5f7fa; }
        .foot { margin-top: 8px; color: #7b8794; font-size: 7.5px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $description }} &middot; Generado {{ $generatedAt }} &middot; {{ count($rows) }} filas</div>
    <table>
        <thead>
            <tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
    <div class="foot">{{ $filename }}</div>
</body>
</html>

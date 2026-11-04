<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $table->title }}</title>
<style>
    @page { margin: 28px 28px 44px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; }
    h1 { font-size: 15px; margin: 0 0 4px; }
    .meta { margin: 0 0 1px; color: #444; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { text-align: left; border-bottom: 1px solid #111; padding: 4px 3px; }
    td { padding: 3px; border-bottom: 1px solid #ddd; }
    .num { text-align: right; white-space: nowrap; }
    .bold { font-weight: bold; }
    .muted { color: #555; }
</style>
</head>
<body>
<h1>{{ $table->title }}</h1>
@foreach ($table->meta as $line)
<p class="meta">{{ $line }}</p>
@endforeach
<p class="meta">{{ $generated }}</p>
<table>
<thead>
<tr>
@foreach ($table->columns as $column)
<th @class(['num' => $column->numeric])>{{ $column->label }}</th>
@endforeach
</tr>
</thead>
<tbody>
@foreach ($table->rows as $row)
<tr @class(['bold' => $row->bold, 'muted' => $row->muted])>
@foreach ($table->columns as $index => $column)
@php($cell = $row->cells[$index] ?? null)
<td @class(['num' => $column->numeric]) @if ($index === 0 && $row->indent > 0) style="padding-left: {{ 3 + $row->indent * 10 }}px" @endif>{{ $cell === null ? '' : ($column->numeric ? $format($cell) : $cell) }}</td>
@endforeach
</tr>
@endforeach
</tbody>
</table>
</body>
</html>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 16px; margin-bottom: 2px; }
    .meta { color: #6b7280; font-size: 10px; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: left; }
    th { background: #f3f4f6; font-weight: bold; }
    tr:nth-child(even) td { background: #fafafa; }
</style>
</head>
<body>
    <h1>AbaiMarket -- {{ $title }}</h1>
    <p class="meta">Generated {{ $generatedAt }}{{ $rangeLabel ? " -- {$rangeLabel}" : '' }}</p>
    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No data for this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

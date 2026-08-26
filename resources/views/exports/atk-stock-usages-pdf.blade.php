<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pengeluaran ATK</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 24px;
            line-height: 1.45;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            color: #111827;
        }
        .header .sub {
            color: #6b7280;
            font-size: 10px;
        }
        .meta {
            text-align: right;
            font-size: 9px;
            color: #6b7280;
        }
        .usage {
            margin-bottom: 22px;
            page-break-inside: avoid;
        }
        .usage:not(:last-child) {
            page-break-after: auto;
        }
        .usage-title {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #2563eb;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 11px;
            color: #1e40af;
            border-radius: 4px 4px 0 0;
        }
        table.info {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #d1d5db;
            margin-top: 0;
            border-top: none;
        }
        table.info td {
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
            vertical-align: top;
        }
        table.info td.label {
            width: 140px;
            background: #f9fafb;
            font-weight: bold;
            color: #374151;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.items th, table.items td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            text-align: left;
        }
        table.items th {
            background: #f3f4f6;
            font-weight: bold;
            color: #374151;
        }
        table.items td.num, table.items th.num {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge.approved  { background: #dcfce7; color: #15803d; }
        .badge.rejected  { background: #fee2e2; color: #b91c1c; }
        .badge.partially_approved { background: #fef3c7; color: #b45309; }
        .badge.pending   { background: #f3f4f6; color: #4b5563; }
        .notes {
            margin-top: 8px;
            font-size: 9px;
            color: #6b7280;
            font-style: italic;
        }
        .footer {
            margin-top: 24px;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
        }
        .empty {
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Laporan Pengeluaran ATK</h1>
            <div class="sub">{{ config('app.name') }}</div>
        </div>
        <div class="meta">
            Dicetak pada: {{ now()->format('d M Y H:i') }}<br>
            Jumlah pengeluaran: {{ $usages->count() }}
        </div>
    </div>

    @forelse($usages as $usage)
        <div class="usage">
            <div class="usage-title">{{ $usage->request_number }}</div>

            <table class="info">
                <tr>
                    <td class="label">Pemohon</td>
                    <td>{{ $usage->requester?->name ?? '-' }}</td>
                    <td class="label">Divisi</td>
                    <td>{{ $usage->division?->name ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label">Tipe</td>
                    <td>{{ ucfirst($usage->request_type ?? '-') }}</td>
                    <td class="label">Tanggal Dibuat</td>
                    <td>{{ $usage->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td>
                        <span class="badge {{ $usage->approval_status }}">{{ ucfirst($usage->approval_status) }}</span>
                    </td>
                    <td class="label">Disetujui Oleh</td>
                    <td>{{ $usage->approved_by?->name ?? '-' }}</td>
                </tr>
            </table>

            <table class="items">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th>Item</th>
                        <th class="num">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($usage->atkStockUsageItems as $item)
                        <tr>
                            <td>{{ $item->category?->name ?? '-' }}</td>
                            <td>{{ $item->item?->name ?? '-' }}</td>
                            <td class="num">{{ $item->quantity }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty">Tidak ada item.</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if($usage->notes)
                <div class="notes">Catatan: {{ $usage->notes }}</div>
            @endif
        </div>
    @empty
        <p class="empty">Tidak ada data pengeluaran ATK.</p>
    @endforelse

    <div class="footer">
        Dokumen ini dibuat otomatis oleh {{ config('app.name') }} pada {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>

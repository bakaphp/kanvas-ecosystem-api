@php
    $money = fn (?float $amount): string => $amount === null ? '' : number_format($amount, 2);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}{{ $number ? ' ' . $number : '' }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 40px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { margin: 0; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .issuer-name { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; }
        .meta { margin-top: 4px; text-align: right; }
        .meta div { margin-bottom: 2px; }
        .status { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 3px; background: #eef2f7; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; }
        .bill-to { margin: 30px 0 20px; }
        .bill-to .label { text-transform: uppercase; font-size: 10px; letter-spacing: 1px; color: #6b7280; margin-bottom: 4px; }
        .lines { margin-top: 10px; }
        .lines th { text-align: left; border-bottom: 2px solid #1f2933; padding: 6px 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .lines td { padding: 8px 4px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 16px; width: 45%; margin-left: 55%; }
        .totals td { padding: 4px; }
        .totals .grand td { border-top: 2px solid #1f2933; font-weight: bold; font-size: 14px; }
        .notes { margin-top: 30px; }
        .notes .block { margin-bottom: 14px; }
        .notes .label { text-transform: uppercase; font-size: 10px; letter-spacing: 1px; color: #6b7280; margin-bottom: 3px; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            <div class="issuer-name">{{ $issuer['name'] }}</div>
            @foreach ($issuer['address_lines'] as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            @if ($issuer['email'])<div class="muted">{{ $issuer['email'] }}</div>@endif
            @if ($issuer['phone'])<div class="muted">{{ $issuer['phone'] }}</div>@endif
            @if ($issuer['website'])<div class="muted">{{ $issuer['website'] }}</div>@endif
        </td>
        <td class="num">
            <h1>{{ $title }}</h1>
            <div class="meta">
                <div><strong>{{ $number ?? 'Draft' }}</strong></div>
                @if ($issued_date)<div class="muted">Issued {{ $issued_date }}</div>@endif
                @if ($expiry_date)<div class="muted">{{ $expiry_label }} {{ $expiry_date }}</div>@endif
                <div class="status">{{ str_replace('_', ' ', $status) }}</div>
            </div>
        </td>
    </tr>
</table>

<div class="bill-to">
    <div class="label">Bill to</div>
    <div><strong>{{ $customer['name'] ?? 'No customer assigned' }}</strong></div>
    @if ($customer['legal_name'] && $customer['legal_name'] !== $customer['name'])
        <div class="muted">{{ $customer['legal_name'] }}</div>
    @endif
    @foreach ($customer['address_lines'] as $line)
        <div class="muted">{{ $line }}</div>
    @endforeach
    @if ($customer['tax_id'])<div class="muted">Tax ID: {{ $customer['tax_id'] }}</div>@endif
    @if ($customer['email'])<div class="muted">{{ $customer['email'] }}</div>@endif
</div>

<table class="lines">
    <thead>
    <tr>
        <th>Description</th>
        <th class="num">Qty</th>
        <th class="num">Unit price</th>
        <th class="num">Discount</th>
        <th class="num">Tax</th>
        <th class="num">Amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>
                {{ $line['description'] }}
                @if ($line['sku'])<div class="muted">{{ $line['sku'] }}</div>@endif
            </td>
            <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</td>
            <td class="num">{{ $money($line['unit_price']) }}</td>
            <td class="num">{{ $money($line['discount']) }}</td>
            <td class="num">{{ $money($line['tax']) }}</td>
            <td class="num">{{ $money($line['total']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Subtotal</td>
        <td class="num">{{ $currency }} {{ $money($totals['subtotal']) }}</td>
    </tr>
    @if ($totals['discount'] > 0)
        <tr>
            <td>Discount</td>
            <td class="num">- {{ $currency }} {{ $money($totals['discount']) }}</td>
        </tr>
    @endif
    <tr>
        <td>Tax</td>
        <td class="num">{{ $currency }} {{ $money($totals['tax']) }}</td>
    </tr>
    <tr class="grand">
        <td>Total</td>
        <td class="num">{{ $currency }} {{ $money($totals['total']) }}</td>
    </tr>
    @if ($totals['paid'] !== null && $totals['paid'] > 0)
        <tr>
            <td>Paid</td>
            <td class="num">- {{ $currency }} {{ $money($totals['paid']) }}</td>
        </tr>
        <tr class="grand">
            <td>Balance due</td>
            <td class="num">{{ $currency }} {{ $money($totals['balance_due']) }}</td>
        </tr>
    @endif
</table>

@if ($notes || $terms)
    <div class="notes">
        @if ($notes)
            <div class="block">
                <div class="label">Notes</div>
                <div>{!! nl2br(e($notes)) !!}</div>
            </div>
        @endif
        @if ($terms)
            <div class="block">
                <div class="label">Terms</div>
                <div>{!! nl2br(e($terms)) !!}</div>
            </div>
        @endif
    </div>
@endif
</body>
</html>

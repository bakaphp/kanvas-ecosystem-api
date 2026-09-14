{{--
    Style strings are kept short and repeated rather than factored into @php helpers: a closure in
    a Blade file breaks the moment the template is rendered anywhere but View::make, and every
    character here is multiplied by the row count — Gmail clips a mail body over ~102KB, which is
    what caps how many items the report can show.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#111;max-width:860px">
    <h1 style="margin-bottom:4px;font-size:20px">Yusen inventory — {{ $company_name }}</h1>

    <p style="color:#555;margin-top:0;font-size:13px">
        @if ($total_discrepancies === 0)
            Everything reconciled. {{ number_format($total_items) }} items checked.
        @else
            <strong>{{ number_format($total_items_in_report) }}</strong>
            item{{ $total_items_in_report === 1 ? '' : 's' }} disagree across
            {{ number_format($total_items) }} counted ({{ number_format((float) $total_quantity) }} units).
        @endif
        @if (! empty($file_name))
            <br>{{ $file_name }}
        @endif
        @if (! empty($generated_at))
            <br><span style="color:#888">Counted {{ $generated_at }}</span>
        @endif
    </p>

    @if ($multi_record_items > 0)
        <p style="background:#fff8e1;border-left:3px solid #f0a000;padding:10px 12px;font-size:13px;margin:16px 0">
            <strong>{{ number_format($multi_record_items) }}</strong>
            item{{ $multi_record_items === 1 ? '' : 's' }} arrived on more than one lot record and were
            summed. If Yusen sends an item-level total on every lot row instead, these are over-counted
            — worth spot-checking one.
        </p>
    @endif

    @foreach ($source_errors as $source => $message)
        <p style="background:#fdecea;border-left:3px solid #b3261e;padding:10px 12px;font-size:13px;margin:16px 0">
            <strong>{{ ucfirst($source) }} could not be checked.</strong> {{ $message }}
        </p>
    @endforeach

    @if (count($items) > 0)
        @if (count($by_type) > 0)
            <p style="font-size:12px;color:#666;margin:16px 0 8px">
                @foreach ($by_type as $type => $count)
                    <span style="display:inline-block;background:#f3f3f3;border-radius:3px;padding:3px 8px;margin-right:6px">{{ str_replace('_', ' ', strtolower($type)) }}: {{ number_format($count) }}</span>
                @endforeach
            </p>
        @endif

        <table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px">
            <thead>
                <tr style="font-size:11px;text-transform:uppercase;color:#666">
                    <th align="left" style="padding:6px 8px;border-bottom:2px solid #d9d9d9">Item</th>
                    <th align="right" style="padding:6px 8px;border-bottom:2px solid #d9d9d9">Yusen</th>
                    @foreach ($sources as $source)
                        <th align="right" style="padding:6px 8px;border-bottom:2px solid #d9d9d9">{{ ucfirst($source) }}</th>
                        <th align="right" style="padding:6px 8px;border-bottom:2px solid #d9d9d9">Diff</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $row)
                    <tr>
                        <td style="padding:6px 8px;border-bottom:1px solid #eee">
                            <strong>{{ $row['description'] ?: $row['item'] }}</strong><br>
                            <span style="color:#888;font-size:11px">{{ $row['item'] }}@if (! empty($row['warehouse_code'])) · {{ $row['warehouse_code'] }}@endif</span>
                        </td>
                        <td align="right" style="padding:6px 8px;border-bottom:1px solid #eee">{{ $row['yusen_quantity'] === null ? '—' : number_format((float) $row['yusen_quantity']) }}</td>

                        @foreach ($sources as $source)
                            @if (! isset($row['by_source'][$source]))
                                <td align="right" style="padding:6px 8px;border-bottom:1px solid #eee;color:#bbb">agrees</td>
                                <td align="right" style="padding:6px 8px;border-bottom:1px solid #eee;color:#bbb">—</td>
                            @elseif ($row['by_source'][$source]['quantity'] === null)
                                <td colspan="2" align="right" style="padding:6px 8px;border-bottom:1px solid #eee;color:#b3261e;font-size:12px">{{ str_replace('_', ' ', strtolower($row['by_source'][$source]['type'])) }}</td>
                            @else
                                <td align="right" style="padding:6px 8px;border-bottom:1px solid #eee">{{ number_format((float) $row['by_source'][$source]['quantity']) }}</td>
                                <td align="right" style="padding:6px 8px;border-bottom:1px solid #eee;color:{{ ($row['by_source'][$source]['difference'] ?? 0) > 0 ? '#b3261e' : (($row['by_source'][$source]['difference'] ?? 0) < 0 ? '#1b5e20' : '#666') }}"><strong>@if ($row['by_source'][$source]['difference'] === null)—@else{{ $row['by_source'][$source]['difference'] > 0 ? '+' : '' }}{{ number_format((float) $row['by_source'][$source]['difference']) }}@endif</strong></td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($total_items_in_report > count($items))
            <p style="color:#888;font-size:12px;margin-top:12px">
                Showing the {{ count($items) }} largest gaps of {{ number_format($total_items_in_report) }}
                items with discrepancies.
            </p>
        @endif

        <p style="color:#888;font-size:11px;margin-top:20px;line-height:1.6">
            <strong>Diff</strong> is Yusen minus that system. Positive means the 3PL is holding more than
            the system thinks. <em>agrees</em> means that system matched Yusen within tolerance.
        </p>
    @endif
</div>

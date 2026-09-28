@php
    // Email clients drop <style> blocks and flexbox, so tiles and the leaderboard are <table>s with
    // inline styles. Keep it that way when editing.
    $muted = 'color: #6b7280;';
    $cell = 'padding: 10px 8px; border-bottom: 1px solid #e5e7eb; font-size: 13px;';
    $head = 'padding: 8px; border-bottom: 2px solid #e5e7eb; font-size: 11px; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; text-align: right;';

    $duration = static function (?int $seconds): string {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        return round($seconds / 3600, 1) . 'h';
    };

    $percent = static fn (?float $rate): string => $rate === null ? '—' : round($rate * 100) . '%';

    // Reps and the team total share every column, so they render through one loop; `is_total`
    // is the only thing that varies the emphasis.
    $lines = [];
    foreach ($rows as $index => $row) {
        $lines[] = ['rank' => $index + 1, 'name' => $row['name'], 'is_total' => false, 'data' => $row];
    }
    $lines[] = ['rank' => '', 'name' => 'Team total', 'is_total' => true, 'data' => $team];
@endphp

<div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #111827; max-width: 760px;">
    <h1 style="margin-bottom: 4px; font-size: 22px;">Engage usage — {{ $range_label }}</h1>
    <p style="margin-top: 0; {{ $muted }}">
        {{ $company_name }} · {{ $from }} – {{ $to }} · {{ $channel_label }}
    </p>

    @if ($team['reps'] === 0)
        <p style="margin-top: 24px;">No Engage activity was recorded for this period.</p>
    @else
        <table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; margin: 20px 0;">
            <tr>
                @foreach ([
                    ['Total sent', number_format($team['total_sent'])],
                    ['AI sent', number_format($team['ai_sent'])],
                    ['Rep sent', number_format($team['rep_sent'])],
                    ['Median rep resp', $duration($team['median_response_seconds'])],
                    ['Customer replies', number_format($team['replies'])],
                    ['Appointments', number_format($team['appointments'])],
                    ['AI share', $percent($team['ai_share'])],
                ] as [$label, $value])
                    <td style="padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; vertical-align: top;">
                        <div style="font-size: 10px; letter-spacing: .04em; text-transform: uppercase; {{ $muted }}">{{ $label }}</div>
                        <div style="font-size: 20px; font-weight: 700; margin-top: 4px;">{{ $value }}</div>
                    </td>
                @endforeach
            </tr>
        </table>

        <h2 style="font-size: 13px; letter-spacing: .04em; text-transform: uppercase; {{ $muted }} margin-bottom: 8px;">
            Leaderboard · {{ $team['reps'] }} rep{{ $team['reps'] === 1 ? '' : 's' }}
        </h2>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse;">
            <tr>
                <th style="{{ $head }} text-align: left;">#</th>
                <th style="{{ $head }} text-align: left;">Rep</th>
                <th style="{{ $head }}">Total sent</th>
                <th style="{{ $head }}">AI sent</th>
                <th style="{{ $head }}">Rep sent</th>
                <th style="{{ $head }}">Rep resp</th>
                <th style="{{ $head }}">Cust reply</th>
                <th style="{{ $head }}">Appts</th>
            </tr>

            @foreach ($lines as $line)
                @php
                    $data = $line['data'];
                    $total = $line['is_total'];
                    $num = $cell . ' text-align: right;' . ($total ? ' font-weight: 700;' : '');
                @endphp
                <tr{{ $total ? ' style="background: #f9fafb;"' : '' }}>
                    <td style="{{ $cell }} {{ $muted }}">{{ $line['rank'] }}</td>
                    <td style="{{ $cell }} font-weight: {{ $total ? 700 : 600 }};">{{ $line['name'] }}</td>
                    <td style="{{ $num }}{{ $total ? '' : ' font-weight: 600;' }}">{{ number_format($data['total_sent']) }}</td>
                    <td style="{{ $num }}{{ $total ? '' : ' ' . $muted }}">{{ number_format($data['ai_sent']) }}</td>
                    <td style="{{ $num }}{{ $total ? '' : ' ' . $muted }}">{{ number_format($data['rep_sent']) }}</td>
                    <td style="{{ $num }}">{{ $duration($data['median_response_seconds']) }}</td>
                    <td style="{{ $num }}">
                        {{ number_format($data['replies']) }}
                        <span style="{{ $muted }} font-size: 11px; font-weight: 400;">{{ $percent($data['reply_rate']) }}</span>
                    </td>
                    <td style="{{ $num }}">{{ number_format($data['appointments']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="{{ $muted }} font-size: 12px; line-height: 1.5; margin-top: 20px;">
        Sent and rep resp are credited to the rep who actually sent the message. Customer replies and
        AI sent are credited to the lead owner, so a rep's replies are the ones their own leads sent back.
        Rep resp is the median time a human took to answer an inbound message — AI replies are excluded.
        The AI agent has no row of its own; its volume shows per rep as AI sent.
        Only messaging that flows through Kanvas is counted, so a rep texting from a personal phone or
        emailing from Outlook will not appear here.
    </p>
</div>

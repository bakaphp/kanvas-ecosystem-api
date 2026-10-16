<tr>
    <td style="padding: 24px 12px; font-family: Arial, sans-serif; color: #263238;">
        <h1 style="margin: 0 0 8px; font-size: 24px; line-height: 1.3;">Daily Leads Digest</h1>
        <p style="margin: 0 0 22px; color: #667085; font-size: 14px;">
            {{ $company->name ?? 'Your company' }} · {{ $period_start->format('M j, Y g:i A') }} – {{ $period_end->format('M j, Y g:i A') }}
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td style="width: 33.33%; padding: 16px; background: #f4f7fb; border-radius: 6px;">
                    <div style="font-size: 12px; color: #667085;">Total leads</div>
                    <strong style="display: block; margin-top: 5px; font-size: 25px;">{{ $total }}</strong>
                </td>
                <td style="width: 8px;"></td>
                <td style="width: 33.33%; padding: 16px; background: #f4f7fb; border-radius: 6px;">
                    <div style="font-size: 12px; color: #667085;">Top source</div>
                    <strong style="display: block; margin-top: 5px; font-size: 25px;">{{ $top_sources[0]['name'] ?? count($top_sources) }}</strong>
                </td>
                <td style="width: 8px;"></td>
                <td style="width: 33.33%; padding: 16px; background: #f4f7fb; border-radius: 6px;">
                    <div style="font-size: 12px; color: #667085;">Excluded suspicious emails</div>
                    <strong style="display: block; margin-top: 5px; font-size: 25px;">{{ $suspicious_emails_count }}</strong>
                </td>
            </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; margin-bottom: 22px;">
            <tr>
                <td style="width: 50%; padding-right: 12px; vertical-align: top;">
                    <h2 style="margin: 0 0 8px; font-size: 16px;">Top sources</h2>
                    @forelse ($top_sources as $source)
                        <p style="margin: 5px 0; font-size: 13px;">{{ $source['name'] }} <span style="color: #667085;">({{ $source['count'] }})</span></p>
                    @empty
                        <p style="margin: 5px 0; color: #667085; font-size: 13px;">No source data</p>
                    @endforelse
                </td>
                <td style="width: 50%; padding-left: 12px; vertical-align: top;">
                    <h2 style="margin: 0 0 8px; font-size: 16px;">Top branches / locations</h2>
                    @forelse ($top_branches as $branch)
                        <p style="margin: 5px 0; font-size: 13px;">{{ $branch['name'] }} <span style="color: #667085;">({{ $branch['count'] }})</span></p>
                    @empty
                        <p style="margin: 5px 0; color: #667085; font-size: 13px;">No branch data</p>
                    @endforelse
                </td>
            </tr>
        </table>

        <h2 style="margin: 0 0 8px; font-size: 16px;">Leads by day</h2>
        <p style="margin: 0 0 22px; color: #667085; font-size: 13px;">
            @forelse ($by_day as $day)
                <span style="display: inline-block; margin: 0 12px 6px 0;">{{ $day['date'] }}: <strong>{{ $day['count'] }}</strong></span>
            @empty
                No leads in this reporting window.
            @endforelse
        </p>

        <h2 style="margin: 0 0 8px; font-size: 16px;">Recent leads</h2>
        <div style="width: 100%; overflow-x: auto;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr>
                        <th style="padding: 9px 6px; text-align: left; border-bottom: 1px solid #d9e0e7;">Lead</th>
                        <th style="padding: 9px 6px; text-align: left; border-bottom: 1px solid #d9e0e7;">Source</th>
                        <th style="padding: 9px 6px; text-align: left; border-bottom: 1px solid #d9e0e7;">Branch / Location</th>
                        <th style="padding: 9px 6px; text-align: left; border-bottom: 1px solid #d9e0e7;">Received</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        <tr>
                            <td style="padding: 9px 6px; border-bottom: 1px solid #edf0f2;">
                                <strong>{{ $lead['name'] }}</strong><br>
                                <span style="color: #667085;">{{ $lead['email'] }}</span>
                                @if ($lead['phone'])
                                    <br><span style="color: #667085;">{{ $lead['phone'] }}</span>
                                @endif
                            </td>
                            <td style="padding: 9px 6px; border-bottom: 1px solid #edf0f2;">{{ $lead['source'] }}</td>
                            <td style="padding: 9px 6px; border-bottom: 1px solid #edf0f2;">{{ $lead['branch'] }}</td>
                            <td style="padding: 9px 6px; border-bottom: 1px solid #edf0f2;">{{ $lead['created_at']->format('M j, g:i A') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="padding: 14px 6px; color: #667085;">No leads in this reporting window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </td>
</tr>

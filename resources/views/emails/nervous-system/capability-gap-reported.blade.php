<div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #111; max-width: 720px;">
    <h1 style="margin-bottom: 4px;">Capability gap: {{ $topic }}</h1>
    <p style="color: #555; margin-top: 0;">
        {{ $company_name }} (company #{{ $company_id }}) · {{ $app_name }} (app #{{ $app_id }})
        · asked {{ $request_count }} time{{ $request_count === 1 ? '' : 's' }}
    </p>

    <h3 style="margin-bottom: 4px;">What was asked</h3>
    @if (! empty($context))
        <div style="white-space: pre-wrap; line-height: 1.5;">{{ $context }}</div>
    @else
        <p style="color: #888;">The agent gave no further context.</p>
    @endif

    @if (! empty($considered_tools))
        <h3 style="margin-bottom: 4px; margin-top: 16px;">Existing tools the agent considered</h3>
        <ul style="margin-top: 0;">
            @foreach ($considered_tools as $tool)
                <li><code>{{ $tool }}</code></li>
            @endforeach
        </ul>
        @if (! empty($why_not_fit))
            <h3 style="margin-bottom: 4px; margin-top: 16px;">Why none of them fit</h3>
            <div style="white-space: pre-wrap; line-height: 1.5;">{{ $why_not_fit }}</div>
        @endif
    @endif

    <hr style="border: none; border-top: 1px solid #e5e5e5; margin: 24px 0;">

    <h3 style="margin-bottom: 4px;">Where it came from</h3>
    <table style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 2px 12px 2px 0; color: #666;">Agent</td>
            <td style="padding: 2px 0;">{{ $agent_name }} (#{{ $agent_id }}@if (! empty($agent_type)), {{ $agent_type }}@endif)</td>
        </tr>
        @if (! empty($requested_by_name) || ! empty($requested_by_email))
            <tr>
                <td style="padding: 2px 12px 2px 0; color: #666;">Asked by</td>
                <td style="padding: 2px 0;">
                    {{ $requested_by_name }}@if (! empty($requested_by_email)) &lt;{{ $requested_by_email }}&gt;@endif
                </td>
            </tr>
        @endif
        @if (! empty($channel))
            <tr>
                <td style="padding: 2px 12px 2px 0; color: #666;">Channel</td>
                <td style="padding: 2px 0;">{{ $channel }}</td>
            </tr>
        @endif
        @if (! empty($lead_id))
            <tr>
                <td style="padding: 2px 12px 2px 0; color: #666;">Lead</td>
                <td style="padding: 2px 0;">
                    @if (! empty($lead_url))<a href="{{ $lead_url }}">{{ $lead_title }}</a>@else{{ $lead_title }}@endif
                    (#{{ $lead_id }})
                </td>
            </tr>
        @endif
        @if (! empty($requested_at))
            <tr>
                <td style="padding: 2px 12px 2px 0; color: #666;">When</td>
                <td style="padding: 2px 0;">{{ $requested_at }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 2px 12px 2px 0; color: #666;">Plan</td>
            <td style="padding: 2px 0;">#{{ $plan_id }} — {{ $plan_title }}@if (! empty($first_requested_at)), first filed {{ $first_requested_at }}@endif</td>
        </tr>
    </table>

    @if (! empty($transcript))
        <h3 style="margin-bottom: 4px; margin-top: 16px;">Recent conversation on the lead</h3>
        <div style="background: #f6f6f6; padding: 12px; border-radius: 4px; font-size: 13px; line-height: 1.5;">
            @foreach ($transcript as $line)
                <div style="white-space: pre-wrap; margin-bottom: 6px;">{{ $line }}</div>
            @endforeach
        </div>
    @endif

    <hr style="border: none; border-top: 1px solid #e5e5e5; margin: 32px 0 16px;">
    <p style="color: #999; font-size: 12px;">
        Filed by report_capability_gap. The plan stays in draft until someone decides whether to build it.
    </p>
</div>

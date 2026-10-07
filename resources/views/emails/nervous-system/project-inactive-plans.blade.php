<div style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #111; max-width: 720px;">
    <h1 style="margin-bottom: 4px;">{{ $title }}</h1>
    <p style="color: #555; margin-top: 0;">
        No activity in over {{ $inactive_hours }}h on the plans below.
        @if (! empty($project_url))
            <a href="{{ $project_url }}">Open project</a>
        @endif
    </p>

    <ul style="padding-left: 20px; line-height: 1.6;">
        @foreach ($plans as $plan)
            <li>
                <strong>{{ $plan['title'] }}</strong>
                <span style="color: #666; font-size: 13px;">({{ $plan['status'] }})</span>
                — {{ $plan['summary'] }}
            </li>
        @endforeach
    </ul>

    <hr style="border: none; border-top: 1px solid #e5e5e5; margin: 32px 0 16px;">
    <p style="color: #999; font-size: 12px;">
        Sent once per project by the inactive-plan sweep.
    </p>
</div>

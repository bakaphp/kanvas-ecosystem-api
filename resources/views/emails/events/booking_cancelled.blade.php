<h2>Your appointment was cancelled</h2>
<p>Hi {{ $participant_name }},</p>
<p><strong>{{ $event_name }}</strong>, which was scheduled for <strong>{{ $start_date }}</strong> at <strong>{{ $start_time }}</strong>@if (! empty($timezone)) ({{ $timezone }})@endif, has been cancelled.</p>
<p>If you'd like to book a new time, please get in touch with us.</p>

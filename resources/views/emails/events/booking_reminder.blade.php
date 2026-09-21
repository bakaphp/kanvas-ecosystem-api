<h2>Reminder: upcoming appointment</h2>
<p>Hi {{ $participant_name }},</p>
<p>This is a reminder that <strong>{{ $event_name }}</strong> is on <strong>{{ $start_date }}</strong> from <strong>{{ $start_time }}</strong> to <strong>{{ $end_time }}</strong>@if (! empty($timezone)) ({{ $timezone }})@endif.</p>
@if (! empty($event->meeting_link))
<p>Join here: <a href="{{ $event->meeting_link }}">{{ $event->meeting_link }}</a></p>
@endif

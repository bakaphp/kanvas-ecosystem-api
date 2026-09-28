<h2>Your appointment is confirmed</h2>
<p>Hi {{ $participant_name }},</p>
<p><strong>{{ $event_name }}</strong> is booked for <strong>{{ $start_date }}</strong> from <strong>{{ $start_time }}</strong> to <strong>{{ $end_time }}</strong>@if (! empty($timezone)) ({{ $timezone }})@endif.</p>
@if (! empty($event->meeting_link))
<p>Join here: <a href="{{ $event->meeting_link }}">{{ $event->meeting_link }}</a></p>
@endif
<p>If you need to change or cancel it, please get in touch with us.</p>

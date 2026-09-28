<h2>Your appointment has changed</h2>
<p>Hi {{ $participant_name }},</p>
<p><strong>{{ $event_name }}</strong> is now on <strong>{{ $start_date }}</strong> from <strong>{{ $start_time }}</strong> to <strong>{{ $end_time }}</strong>@if (! empty($timezone)) ({{ $timezone }})@endif.</p>
@if (! empty($event->meeting_link))
<p>Join here: <a href="{{ $event->meeting_link }}">{{ $event->meeting_link }}</a></p>
@endif
<p>If this time doesn't work for you, please get in touch with us.</p>

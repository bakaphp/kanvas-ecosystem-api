<p>Hi {{ $user->firstname ?? ($user->displayname ?? 'there') }},</p>
<p><strong>{{ $fromUserName ?? 'Someone' }}</strong> mentioned you in a message:</p>
@if (! empty($body))
    <blockquote>{!! nl2br(e($body)) !!}</blockquote>
@endif

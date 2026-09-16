@use('Kanvas\Notifications\Support\MarkdownEmailRenderer')

<p>Hi {{ $user->firstname ?? ($user->displayname ?? 'there') }},</p>
<p><strong>{{ $fromUserName ?? 'Someone' }}</strong> mentioned you in a message:</p>
@if (! empty($body))
    <blockquote>{!! MarkdownEmailRenderer::toEmailHtml($body, allowHtml: false) !!}</blockquote>
@endif

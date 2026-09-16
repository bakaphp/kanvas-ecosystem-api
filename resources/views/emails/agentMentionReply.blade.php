@use('Kanvas\Notifications\Support\MarkdownEmailRenderer')

<h2>{{ $agentName ?? 'Your agent' }} replied to your message</h2>
{!! MarkdownEmailRenderer::toEmailHtml($body ?? '', allowHtml: false) !!}

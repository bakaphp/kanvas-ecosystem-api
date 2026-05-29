<h2>Agent deployment paused</h2>
<p>Agent <strong>{{ $agent_name }}</strong> was not deployed because it does not have a complete Slack or Telegram integration.</p>
<p>Set either both Slack tokens or both Telegram values, then launch the agent again.</p>

@if (! empty($missing_requirements))
    <p>Missing values:</p>
    <ul>
        @foreach ($missing_requirements as $requirement)
            <li>{{ $requirement }}</li>
        @endforeach
    </ul>
@endif

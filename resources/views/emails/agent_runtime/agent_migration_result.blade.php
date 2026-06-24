@if ($success)
    <h2>Migration completed</h2>
    <p>Agent <strong>{{ $agent_name }}</strong> moved to <strong>{{ $destination_machine_name }}</strong>.</p>
    <p>New deployment id: <code>{{ $destination_deployment_id }}</code></p>
@else
    <h2>Migration failed</h2>
    <p>Migration of agent <strong>{{ $agent_name }}</strong> from deployment <code>{{ $source_deployment_id }}</code> did not complete.</p>
    <p>Error: <code>{{ $error_message }}</code></p>
@endif

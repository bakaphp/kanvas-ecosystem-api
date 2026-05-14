@if ($success)
    <h2>Backup completed</h2>
    <p>Backup of agent <strong>{{ $agent_name }}</strong> finished.</p>
    <p>Archive: <code>{{ $file_path }}</code> ({{ $file_size_bytes }} bytes)</p>
    <p>Backup id: <code>{{ $backup_id }}</code></p>
@else
    <h2>Backup failed</h2>
    <p>Backup of agent <strong>{{ $agent_name }}</strong> did not complete.</p>
    <p>Backup id: <code>{{ $backup_id }}</code></p>
    <p>Error: <code>{{ $error_message }}</code></p>
@endif

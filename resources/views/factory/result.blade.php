<!DOCTYPE html>
<html>
<head>
    <title>Result: {{ $file }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d9ff; }
        h2 { color: #ff6b6b; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .btn { display: inline-block; background: #0f3460; color: #00d9ff; padding: 10px 20px; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
        .btn:hover { background: #00d9ff; color: #1a1a2e; }
        .btn-approve { background: #00ff88; color: #1a1a2e; }
        .btn-approve:hover { background: #00cc6a; }
        .btn-reject { background: #ff6b6b; color: #1a1a2e; }
        .btn-reject:hover { background: #cc5555; }
        pre { background: #0f3460; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .status { padding: 15px; border-radius: 5px; margin: 10px 0; }
        .status-success { background: #00ff88; color: #1a1a2e; }
        .status-waiting { background: #ffd93d; color: #1a1a2e; }
        .status-error { background: #ff6b6b; color: #1a1a2e; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #0f3460; }
        th { color: #00d9ff; }
    </style>
</head>
<body>
    <h1>▶️ Run: {{ $file }}</h1>
    <p><a href="/" class="btn">← Back</a></p>
    
    <div class="card">
        @if($result['status'] === 'completed')
            <div class="status status-success">
                <h2>✅ Status: Completed</h2>
            </div>
        @elseif($result['status'] === 'waiting_for_approval')
            <div class="status status-waiting">
                <h2>⏳ Awaiting Approval</h2>
                <p>Node: <strong>{{ $result['waiting_node'] }}</strong></p>
            </div>
            
            <div class="card">
                <h2>Review Context</h2>
                <pre>{{ json_encode($result['context'], JSON_PRETTY_PRINT) }}</pre>
            </div>
            
            <div class="card">
                <h2>Your Decision</h2>
                <form action="/factory/approve" method="POST">
                    @csrf
                    <input type="hidden" name="node_id" value="{{ $result['waiting_node'] }}">
                    <input type="hidden" name="state_id" value="{{ $state_id }}">
                    <button type="submit" name="decision" value="approved" class="btn btn-approve">✅ Approve</button>
                    <button type="submit" name="decision" value="rejected" class="btn btn-reject">❌ Reject</button>
                </form>
            </div>
        @else
            <div class="status status-error">
                <h2>❌ Status: {{ $result['status'] }}</h2>
            </div>
        @endif
        
        <p><strong>Iterations:</strong> {{ $result['iterations'] }}</p>
    </div>
    
    <div class="card">
        <h2>Context</h2>
        <pre>{{ json_encode($result['context'], JSON_PRETTY_PRINT) }}</pre>
    </div>
</body>
</html>

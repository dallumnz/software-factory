<!DOCTYPE html>
<html>
<head>
    <title>Lint: {{ $file }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d9ff; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .btn { display: inline-block; background: #0f3460; color: #00d9ff; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        .btn:hover { background: #00d9ff; color: #1a1a2e; }
        .status { padding: 15px; border-radius: 5px; margin: 10px 0; }
        .status-success { background: #00ff88; color: #1a1a2e; }
        .status-error { background: #ff6b6b; color: #1a1a2e; }
        .status-warning { background: #ffd93d; color: #1a1a2e; }
        ul { list-style: none; padding: 0; }
        li { padding: 8px; border-bottom: 1px solid #0f3460; }
    </style>
</head>
<body>
    <h1>🔍 Lint: {{ $file }}</h1>
    <p><a href="/" class="btn">← Back</a></p>
    
    @if($result['passed'])
        <div class="card">
            <div class="status status-success">
                <h2>✅ No errors found!</h2>
            </div>
        </div>
    @else
        <div class="card">
            <div class="status status-error">
                <h2>❌ Errors found</h2>
            </div>
            <ul>
                @foreach($result['errors'] as $error)
                    <li>ERROR: {{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    
    @if(!empty($result['warnings']))
        <div class="card">
            <div class="status status-warning">
                <h2>⚠️ Warnings</h2>
            </div>
            <ul>
                @foreach($result['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <title>Software Factory</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d9ff; }
        h2 { color: #ff6b6b; margin-top: 30px; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .btn { display: inline-block; background: #0f3460; color: #00d9ff; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn:hover { background: #00d9ff; color: #1a1a2e; }
        .btn-run { background: #00d9ff; color: #1a1a2e; }
        .btn-run:hover { background: #00ff88; }
        .status { padding: 5px 10px; border-radius: 3px; }
        .status-success { background: #00ff88; color: #1a1a2e; }
        .status-error { background: #ff6b6b; color: #1a1a2e; }
        .status-warning { background: #ffd93d; color: #1a1a2e; }
        ul { list-style: none; padding: 0; }
        li { padding: 10px; border-bottom: 1px solid #0f3460; }
        a { color: #00d9ff; }
        pre { background: #0f3460; padding: 15px; border-radius: 5px; overflow-x: auto; }
        code { color: #00ff88; }
        select { background: #0f3460; color: #00d9ff; padding: 8px 12px; border-radius: 5px; border: 1px solid #00d9ff; margin-right: 10px; }
        .model-selector { margin-bottom: 15px; padding: 10px; background: #0f3460; border-radius: 5px; }
        .model-selector label { color: #00d9ff; margin-right: 10px; }
    </style>
</head>
<body>
    <h1>⚡ Software Factory</h1>
    <p>A DOT-based pipeline runner for AI-driven software development.</p>
    
    <div class="card">
        <div class="model-selector">
            <label for="model">Model:</label>
            <select name="model" id="model">
                @foreach($models as $model)
                    <option value="{{ $model }}" {{ $model === 'qwen3-14b' ? 'selected' : '' }}>{{ $model }}</option>
                @endforeach
            </select>
            <span style="color: #888; font-size: 0.9em;">Select model before running pipeline</span>
        </div>
        
        <h2>Available Pipelines</h2>
        <ul>
            @foreach($examples as $example)
                <li>
                    <strong>{{ $example }}</strong>
                    <br>
                    <a href="/factory/visualize/{{ $example }}" class="btn">Visualize</a>
                    <a href="/factory/lint/{{ $example }}" class="btn">Lint</a>
                    <a href="/factory/run/{{ $example }}" class="btn btn-run">Run (GET)</a>
                    <form action="/factory/run" method="POST" style="display:inline;">
                        @csrf
                        <input type="hidden" name="file" value="{{ $example }}">
                        <input type="hidden" name="model" id="model_{{ $loop->index }}" value="qwen3-14b">
                        <button type="submit" class="btn btn-run" onclick="document.getElementById('model_{{ $loop->index }}').value = document.getElementById('model').value">Run</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>
    
    <div class="card">
        <h2>Quick Commands</h2>
        <pre>
# CLI Commands
php artisan factory:run examples/simple.dot
php artisan factory:visualize examples/simple.dot
php artisan factory:lint examples/simple.dot

# With specific model
php artisan factory:run examples/simple.dot --model=qwen3-14b
        </pre>
    </div>
</body>
</html>

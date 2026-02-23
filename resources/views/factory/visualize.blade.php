<!DOCTYPE html>
<html>
<head>
    <title>Visualize: {{ $file }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d9ff; }
        h2 { color: #ff6b6b; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .btn { display: inline-block; background: #0f3460; color: #00d9ff; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        .btn:hover { background: #00d9ff; color: #1a1a2e; }
        .node { padding: 8px 15px; margin: 5px 0; border-radius: 5px; }
        .node-start { background: #ffd93d; color: #1a1a2e; }
        .node-end { background: #ff6b6b; color: #1a1a2e; }
        .node-box { background: #0f3460; color: #00d9ff; }
        .node-hexagon { background: #9b59b6; color: white; }
        .node-diamond { background: #e74c3c; color: white; }
        .node-component { background: #00ff88; color: #1a1a2e; }
        .node-tripleoctagon { background: #3498db; color: white; }
        .node-parallelogram { background: #f39c12; color: #1a1a2e; }
        .node-house { background: #1abc9c; color: #1a1a2e; }
        pre { background: #0f3460; padding: 15px; border-radius: 5px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #0f3460; }
        th { color: #00d9ff; }
    </style>
</head>
<body>
    <h1>📊 {{ $file }}</h1>
    <p><a href="/" class="btn">← Back</a></p>
    
    <div class="card">
        <h2>Graph Info</h2>
        <p><strong>ID:</strong> {{ $graph->id ?: '(none)' }}</p>
        <p><strong>Goal:</strong> {{ $graph->goal ?: '(none)' }}</p>
    </div>
    
    <div class="card">
        <h2>Nodes ({{ count($graph->nodes) }})</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Shape</th>
                <th>Label</th>
            </tr>
            @foreach($graph->nodes as $node)
                <tr>
                    <td>{{ $node->id }}</td>
                    <td><span class="node node-{{ strtolower($node->shape) }}">{{ $node->shape }}</span></td>
                    <td>{{ $node->label }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    
    <div class="card">
        <h2>Edges ({{ count($graph->edges) }})</h2>
        <ul>
            @foreach($graph->edges as $edge)
                <li>
                    <strong>{{ $edge->from }}</strong> → <strong>{{ $edge->to }}</strong>
                    @if($edge->label)
                        <span class="node node-diamond">[{{ $edge->label }}]</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    
    <div class="card">
        <h2>Raw DOT</h2>
        <pre>{{ $graph->id ? file_get_contents(base_path('examples/'.$file)) : '' }}</pre>
    </div>
</body>
</html>

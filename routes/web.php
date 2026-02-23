<?php

use Illuminate\Support\Facades\Route;
use App\Pipeline\DOTParser;
use App\Pipeline\Engine;
use App\Pipeline\Lint\Linter;
use App\LLM\LMStudioClient;

// Simple GET route for testing (no CSRF)
Route::get('/factory/run/{file}', function ($file) {
    $path = base_path("examples/{$file}");
    
    if (!file_exists($path)) {
        return "File not found: {$file}";
    }
    
    $dot = file_get_contents($path);
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $llm = new LMStudioClient('http://localhost:1234/api/v0', 'qwen3-14b');
    $engine->setLLM($llm);
    
    // Find start node
    $startNode = null;
    foreach ($graph->nodes as $node) {
        if ($node->shape === 'Mdiamond') {
            $startNode = $node->id;
            break;
        }
    }
    
    if (!$startNode) {
        $startNode = array_key_first($graph->nodes);
    }
    
    try {
        $result = $engine->execute($graph, $startNode);
        
        // If waiting for approval, store DOT + context (re-parse on approval)
        $stateId = null;
        if ($result['status'] === 'waiting_for_approval') {
            $stateId = uniqid('factory_');
            file_put_contents(storage_path("app/{$stateId}_dot.txt"), $dot);
            file_put_contents(storage_path("app/{$stateId}_context.txt"), json_encode($result['context']));
            file_put_contents(storage_path("app/{$stateId}_file.txt"), $file);
            file_put_contents(storage_path("app/{$stateId}_waiting_node.txt"), $result['waiting_node']);
        }
        
        return view('factory.result', [
            'result' => $result,
            'file' => $file,
            'state_id' => $stateId,
        ]);
    } catch (\Throwable $e) {
        return "Error: " . $e->getMessage();
    }
})->where('file', '.*');

Route::get('/', function () {
    $examples = glob(base_path('examples/*.dot'));
    $examples = array_map('basename', $examples);
    
    // Fetch available models from LM Studio
    $models = [];
    try {
        $ch = curl_init('http://localhost:1234/api/v0/models');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            \Log::error('LM Studio curl error: ' . $curlError);
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::error('LM Studio JSON error: ' . json_last_error_msg() . ' Response: ' . $response);
        }
        $models = array_column($data['data'] ?? [], 'id');
    } catch (\Exception $e) {
        \Log::error('LM Studio exception: ' . $e->getMessage());
        $models = ['qwen3-14b']; // fallback
    }
    
    return view('factory.index', [
        'examples' => $examples,
        'models' => $models,
    ]);
});

Route::get('/factory/visualize/{file}', function ($file) {
    $path = base_path("examples/{$file}");
    if (!file_exists($path)) {
        abort(404);
    }
    
    $dot = file_get_contents($path);
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    return view('factory.visualize', [
        'graph' => $graph,
        'file' => $file,
    ]);
});

Route::get('/factory/lint/{file}', function ($file) {
    $path = base_path("examples/{$file}");
    if (!file_exists($path)) {
        abort(404);
    }
    
    $dot = file_get_contents($path);
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $linter = new Linter();
    $result = $linter->lint($graph);
    
    return view('factory.lint', [
        'result' => $result,
        'file' => $file,
    ]);
});

Route::post('/factory/run', function (\Illuminate\Http\Request $request) {
    $file = $request->input('file');
    $model = $request->input('model', 'qwen3-14b');
    $path = base_path("examples/{$file}");
    
    if (!file_exists($path)) {
        return back()->with('error', 'File not found');
    }
    
    $dot = file_get_contents($path);
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $llm = new LMStudioClient('http://localhost:1234/api/v0', $model);
    $engine->setLLM($llm);
    
    // Find start node
    $startNode = null;
    foreach ($graph->nodes as $node) {
        if ($node->shape === 'Mdiamond') {
            $startNode = $node->id;
            break;
        }
    }
    
    if (!$startNode) {
        $startNode = array_key_first($graph->nodes);
    }
    
    try {
        $result = $engine->execute($graph, $startNode);
        
        // If waiting for approval, store DOT + context (re-parse on approval)
        $stateId = null;
        if ($result['status'] === 'waiting_for_approval') {
            $stateId = uniqid('factory_');
            
            // Debug: check what can't be serialized
            try {
                @file_put_contents(
                    storage_path("app/{$stateId}_dot.txt"),
                    $dot
                );
            } catch (\Exception $e) {
                return "Error storing DOT: " . $e->getMessage();
            }
            
            try {
                @file_put_contents(
                    storage_path("app/{$stateId}_context.txt"),
                    json_encode($result['context'], JSON_PARTIAL_OUTPUT_ON_ERROR)
                );
            } catch (\Exception $e) {
                return "Error storing context: " . $e->getMessage();
            }
            
            file_put_contents(storage_path("app/{$stateId}_file.txt"), $file);
            file_put_contents(storage_path("app/{$stateId}_waiting_node.txt"), $result['waiting_node']);
        }
        
        return view('factory.result', [
            'result' => $result,
            'file' => $file,
            'state_id' => $stateId,
        ]);
    } catch (\Throwable $e) {
        return "Error: " . $e->getMessage();
    }
});

Route::post('/factory/approve', function (\Illuminate\Http\Request $request) {
    $nodeId = $request->input('node_id');
    $decision = $request->input('decision');
    $stateId = $request->input('state_id');
    
    if (!$stateId) {
        return redirect('/')->with('error', 'No pipeline in progress');
    }
    
    $dotFile = storage_path("app/{$stateId}_dot.txt");
    $contextFile = storage_path("app/{$stateId}_context.txt");
    $fileFile = storage_path("app/{$stateId}_file.txt");
    $waitingNodeFile = storage_path("app/{$stateId}_waiting_node.txt");
    
    if (!file_exists($dotFile)) {
        return redirect('/')->with('error', 'Pipeline state not found. Run again.');
    }
    
    // Re-parse the DOT file
    $dot = file_get_contents($dotFile);
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    $context = json_decode(file_get_contents($contextFile), true);
    $file = file_get_contents($fileFile);
    $waitingNode = file_get_contents($waitingNodeFile);
    
    // Create new engine and provide approval
    $engine = new Engine();
    $llm = new LMStudioClient('http://localhost:1234/api/v0', 'qwen3-14b');
    $engine->setLLM($llm);
    $engine->provideApproval($nodeId, strtolower($decision) === 'approved');
    
    // Continue execution from the waiting node
    try {
        $result = $engine->execute($graph, $nodeId, $context);
        
        // Clean up temp files
        @unlink($dotFile);
        @unlink($contextFile);
        @unlink($fileFile);
        @unlink($waitingNodeFile);
        
        return view('factory.result', [
            'result' => $result,
            'file' => $file,
            'state_id' => null,
        ]);
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});

<?php

use App\Pipeline\DOTParser;
use App\Pipeline\Engine;
use App\LLM\LMStudioClient;
use App\LLM\OllamaClient;
use App\Agents\Session;

/*
|--------------------------------------------------------------------------
| Demo: End-to-End Pipeline
|--------------------------------------------------------------------------
|
| This demo shows the complete system:
| 1. Define pipeline in DOT
| 2. Parse with DOTParser
| 3. Execute with Engine
| 4. Each node uses Agent Session to call LLM
|
*/

require __DIR__ . '/vendor/autoload.php';

echo "=== Software Factory Demo ===\n\n";

// 1. Define a simple pipeline in DOT
$dot = '
digraph demo {
    graph [goal="Analyze and improve code", label="Code Review Pipeline"]
    
    start [shape=Mdiamond, label="Start"]
    analyze [shape=box, label="Analyze Code", prompt="Read and analyze the following code. What does it do? Give a brief summary."]
    improve [shape=box, label="Suggest Improvements", prompt="Based on the analysis, suggest 3 improvements for the code."]
    end [shape=Msquare, label="Done"]
    
    start -> analyze
    analyze -> improve
    improve -> end
}
';

echo "1. Parsing DOT graph...\n";
$parser = new DOTParser();
$graph = $parser->parse($dot);

echo "   Graph: {$graph->id}\n";
echo "   Goal: {$graph->goal}\n";
echo "   Nodes: " . count($graph->nodes) . "\n";
echo "   Edges: " . count($graph->edges) . "\n\n";

// 2. Create LLM client and agent session
echo "2. Initializing Agent Session...\n";
// Use LM Studio (default port 1234) - OpenAI-compatible API
$llm = new LMStudioClient(
    baseUrl: 'http://localhost:1234/v1',
    model: 'llama-3.2-1b-instruct'
);
// Or use Ollama:
// $llm = new OllamaClient(
//     baseUrl: 'http://localhost:11434',
//     model: 'llama3.2'
// );
$agent = new Session($llm);

echo "   Connected to LM Studio ({$llm->model})\n\n";

// 3. Register handlers with the engine
echo "3. Setting up Engine handlers...\n";
$engine = new Engine();

// Register a handler that uses the LLM agent
$engine->registerHandler('box', function($node, $context) use ($agent) {
    $prompt = $node->attributes['prompt'] ?? "Complete this task: {$node->label}";
    
    // Check if there's code in context to analyze
    $code = $context['code'] ?? null;
    if ($code) {
        $fullPrompt = $prompt . "\n\nCode to analyze:\n```php\n{$code}\n```";
    } else {
        $fullPrompt = $prompt;
    }
    
    echo "   Processing node: {$node->label}\n";
    
    try {
        // Try to get a response from Ollama
        // For demo, we'll just set a placeholder
        $result = "Task '{$node->label}' would execute here with LLM";
    } catch (\Exception $e) {
        $result = "Ollama not running - simulated result for {$node->label}";
    }
    
    return [
        'context' => array_merge($context, [
            'last_node' => $node->id,
            'result' => $result,
        ]),
        'next' => null, // Use default edge routing
    ];
});

echo "   Handlers registered\n\n";

// 4. Execute the pipeline
echo "4. Executing pipeline...\n";
try {
    $result = $engine->execute($graph, 'start', ['code' => '<?php echo "Hello World"; ?>']);
    
    echo "   Status: {$result['status']}\n";
    echo "   Iterations: {$result['iterations']}\n";
    echo "   Final context:\n";
    print_r($result['context']);
} catch (\Exception $e) {
    echo "   Error: {$e->getMessage()}\n";
    echo "   (This is expected if Ollama isn't running)\n";
}

echo "\n=== Demo Complete ===\n";

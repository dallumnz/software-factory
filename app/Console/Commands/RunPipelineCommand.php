<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Pipeline\DOTParser;
use App\Pipeline\Engine;
use App\Pipeline\Stylesheets\ModelStylesheet;
use App\LLM\LMStudioClient;
use App\LLM\OllamaClient;
use App\LLM\ProviderManager;

class RunPipelineCommand extends Command
{
    protected $signature = 'factory:run {file? : The DOT file to run}
                            {--dot= : Inline DOT graph}
                            {--provider=lmstudio : LLM provider (lmstudio, ollama)}
                            {--model= : Model to use}
                            {--context= : JSON context to pass}
                            {--approve= : Node ID to auto-approve (e.g., --approve=review)}
                            {--decision=approved : Decision for approval (approved or rejected)}
                            {--auto : Auto-approve all approval nodes without prompting}
                            {--artifacts= : Directory to save artifacts (prompts/responses)}
                            {--no-artifacts : Disable artifact saving}';

    protected $description = 'Run a software factory pipeline from a DOT graph';

    public function handle(): int
    {
        $dotFile = $this->argument('file');
        $dotString = $this->option('dot');
        $provider = $this->option('provider') ?? 'lmstudio';
        $model = $this->option('model') ?? 'qwen3-14b';
        $contextJson = $this->option('context');
        $approveNode = $this->option('approve');
        $decision = $this->option('decision') ?? 'approved';
        $auto = $this->option('auto');
        $artifactsDir = $this->option('artifacts');
        $noArtifacts = $this->option('no-artifacts');

        // Parse DOT
        if ($dotString) {
            $dot = $dotString;
        } elseif ($dotFile) {
            if (!file_exists($dotFile)) {
                $this->error("File not found: {$dotFile}");
                return 1;
            }
            $dot = file_get_contents($dotFile);
        } else {
            $this->error('Provide either a DOT file or --dot option');
            return 1;
        }

        $this->info('Parsing DOT graph...');
        $parser = new DOTParser();
        $graph = $parser->parse($dot);

        $this->info("Graph: {$graph->id}");
        $this->info("Goal: {$graph->goal}");
        $this->info('Nodes: ' . count($graph->nodes));
        $this->info('Edges: ' . count($graph->edges));

        // Set up LLM
        $this->info("Connecting to {$provider}...");
        
        $llm = match($provider) {
            'ollama' => new OllamaClient('http://localhost:11434', $model),
            'lmstudio' => new LMStudioClient('http://localhost:1234/api/v0', $model),
            default => new LMStudioClient('http://localhost:1234/api/v0', $model),
        };

        // Parse context
        $context = [];
        if ($contextJson) {
            $context = json_decode($contextJson, true) ?? [];
        }

        // Run pipeline
        $this->info('Executing pipeline...');
        
        $engine = new Engine();
        
        // Configure artifacts
        if ($noArtifacts) {
            $engine->setArtifactDir('');
        } elseif ($artifactsDir) {
            $engine->setArtifactDir($artifactsDir);
            $this->info("Saving artifacts to: {$artifactsDir}");
        }
        
        // Configure model stylesheet
        $stylesheet = new ModelStylesheet();
        $engine->setStylesheet($stylesheet);
        $engine->setModel($model);
        
        $engine->setLLM($llm);

        $approved = strtolower($decision) === 'approved';

        try {
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

            $result = $engine->execute($graph, $startNode, $context);
            
            // Handle approval nodes - loop until not waiting
            while ($result['status'] === 'waiting_for_approval') {
                $waitingNode = $result['waiting_node'];
                
                if ($approveNode || $auto) {
                    // Auto-approve - set approval in the context we're passing
                    $targetNode = $approveNode ?? $waitingNode;
                    $this->info("Auto-approving node: {$targetNode} ({$decision})");
                    
                    // Add approval to the context and continue
                    $result['context']["approval_{$targetNode}"] = $approved ? 'approved' : 'rejected';
                    $result = $engine->execute($graph, $targetNode, $result['context']);
                } else {
                    // Prompt user
                    $this->warn("Pipeline waiting for approval at node: {$waitingNode}");
                    $this->info("Use --approve={$waitingNode} --decision=approved to auto-approve");
                    break;
                }
            }

            $this->info('Status: ' . $result['status']);
            $this->info('Iterations: ' . $result['iterations']);
            
            if ($this->option('verbose')) {
                $this->info('Context:');
                print_r($result['context']);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}

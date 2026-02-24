<?php

namespace App\Pipeline;

use App\Pipeline\Node;
use App\Pipeline\Edge;
use App\Pipeline\Stylesheets\ModelStylesheet;
use App\LLM\ClientInterface;
use App\Agents\Session;

class Engine
{
    protected array $handlers = [];
    protected array $context = [];
    protected array $tools = [];
    protected ?Graph $graph = null;
    protected ?ClientInterface $llm = null;
    protected ?Session $session = null;
    protected string $artifactDir = '';
    protected int $artifactCounter = 0;
    protected ?ModelStylesheet $stylesheet = null;
    protected string $model = 'default';
    
    public function __construct()
    {
        $this->registerDefaultHandlers();
        $this->registerDefaultTools();
    }
    
    /**
     * Register default available tools.
     */
    protected function registerDefaultTools(): void
    {
        // Use bash for all file operations - simpler and more reliable
        $this->tools = [
            'bash' => 'Execute any shell command. Use bash for file operations ONLY. Use echo, printf, or cat to write/read files. NEVER use python, perl or other languages.',
        ];
    }
    
    /**
     * Get the artifact directory (lazy-loaded).
     */
    protected function getArtifactDir(): string
    {
        if (empty($this->artifactDir)) {
            // Try to get storage path safely
            try {
                $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
                $this->artifactDir = $basePath . '/storage/artifacts';
            } catch (\Exception $e) {
                $this->artifactDir = __DIR__ . '/../../../storage/artifacts';
            }
            if (!is_dir($this->artifactDir)) {
                @mkdir($this->artifactDir, 0755, true);
            }
        }
        return $this->artifactDir;
    }
    
    /**
     * Set artifact storage directory. Empty string disables artifacts.
     */
    public function setArtifactDir(string $dir): void
    {
        if (empty($dir)) {
            $this->artifactDir = '';
            return;
        }
        $this->artifactDir = $dir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * Set the model stylesheet.
     */
    public function setStylesheet(ModelStylesheet $stylesheet): void
    {
        $this->stylesheet = $stylesheet;
    }
    
    /**
     * Set the model name (for stylesheet lookup).
     */
    public function setModel(string $model): void
    {
        $this->model = $model;
    }
    
    /**
     * Save an artifact (prompt/response) to disk.
     */
    protected function saveArtifact(string $type, string $nodeId, string $content): string
    {
        $artifactDir = $this->getArtifactDir();
        if (empty($artifactDir)) {
            return '';
        }
        
        $this->artifactCounter++;
        $timestamp = date('Y-m-d_His');
        $filename = "{$timestamp}_{$this->artifactCounter}_{$nodeId}_{$type}.txt";
        $path = "{$artifactDir}/{$filename}";
        
        $header = "=== {$type} for node: {$nodeId} ===\n";
        $header .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        $header .= "==========================\n\n";
        
        file_put_contents($path, $header . $content);
        
        return $filename;
    }
    
    /**
     * Set the LLM client for code generation.
     */
    public function setLLM(ClientInterface $llm): void
    {
        $this->llm = $llm;
        $this->session = new Session($llm);
        $this->session->setTools($this->tools);
    }
    
    /**
     * Set available tools for the agent session.
     */
    public function setTools(array $tools): void
    {
        $this->tools = $tools;
        if ($this->session) {
            $this->session->setTools($tools);
        }
    }
    
    /**
     * Get the current session (for external access).
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }
    
    /**
     * Register a handler for a node shape/type.
     */
    public function registerHandler(string $shape, callable $handler): void
    {
        $this->handlers[$shape] = $handler;
    }
    
    /**
     * Execute a graph starting from a node.
     */
    public function execute(Graph $graph, string $startNodeId, array $initialContext = []): array
    {
        $this->graph = $graph;
        $this->context = $initialContext;
        $currentNodeId = $startNodeId;
        $maxIterations = 100;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            $node = $graph->getNode($currentNodeId);
            
            if (!$node) {
                throw new \RuntimeException("Node not found: {$currentNodeId}");
            }
            
            // Check if it's the exit node
            if ($node->shape === 'Msquare') {
                return [
                    'status' => 'completed',
                    'context' => $this->context,
                    'iterations' => $iteration,
                ];
            }
            
            // Get handler for this node shape
            $handler = $this->handlers[$node->shape] ?? $this->handlers['box'] ?? null;
            
            if (!$handler) {
                throw new \RuntimeException("No handler for shape: {$node->shape}");
            }
            
            // Execute handler (pass node, context, and graph for edge navigation)
            $result = $handler($node, $this->context, $graph);
            
            // Update context with result
            $this->context = array_merge($this->context, $result['context'] ?? []);
            
            // Check if waiting for human approval - return early
            if (isset($result['status']) && $result['status'] === 'waiting_for_approval') {
                return [
                    'status' => 'waiting_for_approval',
                    'context' => $this->context,
                    'waiting_node' => $currentNodeId,
                    'iterations' => $iteration,
                ];
            }
            
            // Check for parallel execution
            if (isset($result['parallel']) && $result['parallel'] === true) {
                $branches = $result['branches'] ?? [];
                $parallelId = $node->id;
                
                // Execute all branches and collect results
                $allBranchResults = [];
                foreach ($branches as $branchNodeId) {
                    // Execute each branch
                    $branchResult = $this->executeBranch($graph, $branchNodeId, $this->context, $parallelId);
                    $allBranchResults[$branchNodeId] = $branchResult;
                    
                    // Merge branch context back
                    $this->context = array_merge($this->context, $branchResult['context'] ?? []);
                }
                
                // Store all results for fan-in node
                $this->context["parallel_results_{$parallelId}"] = $allBranchResults;
                
                // Find the fan-in node (tripleoctagon) connected to all branches
                $nextNodeId = $this->findFanInNode($graph, $branches);
            } else {
                // Determine next node
                $nextNodeId = $result['next'] ?? null;
            }
            
            if (!$nextNodeId) {
                // Find first outgoing edge
                $edges = $graph->getOutgoingEdges($currentNodeId);
                if (empty($edges)) {
                    throw new \RuntimeException("No outgoing edges from node: {$currentNodeId}");
                }
                $firstEdge = array_values($edges)[0] ?? null;
                $nextNodeId = $firstEdge?->to ?? throw new \RuntimeException("Edge has no destination from node: {$currentNodeId}");
            }
            
            $currentNodeId = $nextNodeId;
        }
        
        throw new \RuntimeException("Max iterations ({$maxIterations}) exceeded");
    }
    
    /**
     * Register default handlers.
     */
    protected function registerDefaultHandlers(): void
    {
        // Start node - just pass through
        $this->handlers['Mdiamond'] = function(Node $node, array $context) {
            return [
                'context' => $context,
                'next' => null, // Will find first outgoing edge
            ];
        };
        
        // Exit node - handled in main loop
        $this->handlers['Msquare'] = function(Node $node, array $context, Graph $graph) {
            return [
                'context' => $context,
                'next' => null,
            ];
        };
        
        // Default codergen handler - executes LLM with node prompt
        $this->handlers['box'] = function(Node $node, array $context, Graph $graph) {
            // Get the prompt from node attributes or build from label
            $prompt = $node->attributes['prompt'] ?? $node->label ?? 'Complete this task';
            
            // Add graph goal to context if available
            $goal = $graph->goal ?? '';
            
            $response = '';
            $status = 'no_llm';
            $error = null;
            
            // Use session if LLM is available
            if ($this->session) {
                try {
                    // Initialize session with context
                    $this->session->context = $context;
                    
                    // Build full prompt with context (apply stylesheet)
                    $fullPrompt = $prompt;
                    
                    // Apply stylesheet prefix/suffix
                    if ($this->stylesheet) {
                        $fullPrompt = $this->stylesheet->applyPrompt($this->model, $fullPrompt);
                    }
                    
                    if (!empty($context)) {
                        $fullPrompt .= "\n\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
                    }
                    if ($goal) {
                        $fullPrompt .= "\n\nGoal: {$goal}";
                    }
                    
                    // Execute the agent
                    $result = $this->session->submit($fullPrompt);
                    $response = $result['response'] ?? '';
                    $status = $result['status'] ?? 'completed';
                    
                    // Save artifacts
                    if ($fullPrompt) {
                        $this->saveArtifact('prompt', $node->id, $fullPrompt);
                    }
                    if ($response) {
                        $this->saveArtifact('response', $node->id, $response);
                    }
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                    $status = 'error';
                }
            }
            
            return [
                'context' => array_merge($context, [
                    'last_node' => $node->id,
                    'last_label' => $node->label,
                    'llm_response' => $response,
                    'llm_status' => $status,
                    'llm_error' => $error,
                ]),
                'next' => null,
            ];
        };
        
        // Human approval handler (hexagon) - pauses for human input
        $this->handlers['hexagon'] = function(Node $node, array $context, Graph $graph) {
            // Check if human has already provided approval/rejection
            $approvalKey = "approval_{$node->id}";
            $approval = $context[$approvalKey] ?? null;
            
            if ($approval === null) {
                // Waiting for human approval - mark as waiting and halt
                return [
                    'context' => array_merge($context, [
                        'waiting_for_human' => $node->id,
                        'waiting_for_approval' => true,
                    ]),
                    'next' => null, // Halt execution until approval provided
                    'status' => 'waiting_for_approval',
                ];
            }
            
            // Human has responded - route based on approval
            $label = strtolower($approval) === 'approved' ? 'approved' : 'rejected';
            $edges = $graph->getOutgoingEdgesByLabel($node->id, $label);
            
            if (empty($edges)) {
                throw new \RuntimeException(
                    "No edge found for label '{$label}' from node: {$node->id}"
                );
            }
            
            $edges = array_values($edges);
            
            return [
                'context' => array_merge($context, [
                    'last_approval' => $approval,
                    'waiting_for_approval' => false,
                ]),
                'next' => $edges[0]->to,
            ];
        };
        
        // Conditional handler (diamond) - evaluates conditions
        $this->handlers['diamond'] = function(Node $node, array $context, Graph $graph) {
            // Get condition from node attributes
            $condition = $node->attributes['condition'] ?? $node->label ?? '';
            
            // Evaluate the condition
            $result = $this->evaluateCondition($condition, $context);
            
            // Route based on true/false
            $label = $result ? 'true' : 'false';
            $edges = $graph->getOutgoingEdgesByLabel($node->id, $label);
            
            // If no labeled edges, fall back to first (true) or second (false)
            if (empty($edges)) {
                $allEdges = $graph->getOutgoingEdges($node->id);
                $allEdges = array_values($allEdges);
                if ($result && isset($allEdges[0]) && $allEdges[0]->to) {
                    $nextNodeId = $allEdges[0]->to;
                } elseif (!$result && isset($allEdges[1]) && $allEdges[1]->to) {
                    $nextNodeId = $allEdges[1]->to;
                } elseif (isset($allEdges[0]) && $allEdges[0]->to) {
                    $nextNodeId = $allEdges[0]->to;
                } else {
                    throw new \RuntimeException("No outgoing edges from node: {$node->id}");
                }
            } else {
                $nextNodeId = $edges[0]->to ?? null;
            }
            
            return [
                'context' => array_merge($context, [
                    'last_condition' => $condition,
                    'condition_result' => $result,
                ]),
                'next' => $nextNodeId,
            ];
        };
        
        // Parallel execution handler (component) - forks execution to multiple branches
        $this->handlers['component'] = function(Node $node, array $context, Graph $graph) {
            $edges = $graph->getOutgoingEdges($node->id);
            
            if (empty($edges)) {
                throw new \RuntimeException("No outgoing edges from parallel node: {$node->id}");
            }
            
            // Collect all branch node IDs
            $branches = array_map(fn($e) => $e->to, $edges);
            
            return [
                'context' => array_merge($context, [
                    'parallel_branches' => $branches,
                    'parallel_results' => [],
                    'branch_count' => count($branches),
                ]),
                'next' => $branches[0] ?? null, // Start with first branch
                'parallel' => true,
                'branches' => $branches,
            ];
        };
        
        // Fan-in handler (tripleoctagon) - collects results from parallel branches
        $this->handlers['tripleoctagon'] = function(Node $node, array $context, Graph $graph) {
            // Check if all branches have completed
            $branchKey = "branches_completed_{$node->id}";
            $completed = $context[$branchKey] ?? [];
            
            // Mark this node as reached
            $completed[] = $node->id;
            
            // Get the results from all branches
            $resultsKey = "parallel_results_{$node->id}";
            $results = $context[$resultsKey] ?? [];
            
            return [
                'context' => array_merge($context, [
                    $branchKey => $completed,
                    $resultsKey => $results,
                    'fan_in_complete' => true,
                ]),
                'next' => null, // Will find next node
            ];
        };
        
        // Tool handler (parallelogram) - executes specific tools
        $this->handlers['parallelogram'] = function(Node $node, array $context, Graph $graph) {
            // Get tool name and args from node attributes
            $tool = $node->attributes['tool'] ?? null;
            
            if (!$tool) {
                throw new \RuntimeException("Tool node missing 'tool' attribute: {$node->id}");
            }
            
            // Parse tool arguments from attributes
            $args = [];
            foreach ($node->attributes as $key => $value) {
                if (!in_array($key, ['shape', 'label', 'tool'])) {
                    // Substitute context variables
                    $value = $this->substituteContext($value, $context);
                    $args[$key] = $value;
                }
            }
            
            // Execute the tool
            $result = $this->executeTool($tool, $args);
            
            return [
                'context' => array_merge($context, [
                    'last_tool' => $tool,
                    'tool_result' => $result,
                    "tool_result_{$node->id}" => $result,
                ]),
                'next' => null,
            ];
        };
        
        // Manager loop handler (house) - spawns subagents for parallel work
        $this->handlers['house'] = function(Node $node, array $context, Graph $graph) {
            // Get agent config from node attributes
            $agentType = $node->attributes['agent'] ?? $node->attributes['type'] ?? 'default';
            $prompt = $node->attributes['prompt'] ?? $node->label ?? 'Complete this task';
            $maxAgents = (int)($node->attributes['max'] ?? $node->attributes['max_agents'] ?? 5);
            
            // Get tasks - either from context or parse from attributes
            $tasksKey = $node->attributes['tasks'] ?? null;
            $tasks = $tasksKey ? ($context[$tasksKey] ?? []) : [];
            
            // If no tasks in context, look for task_{id} attributes
            if (empty($tasks)) {
                foreach ($node->attributes as $key => $value) {
                    if (str_starts_with($key, 'task_')) {
                        $tasks[] = [
                            'id' => $key,
                            'prompt' => $this->substituteContext($value, $context),
                        ];
                    }
                }
            }
            
            if (empty($tasks)) {
                throw new \RuntimeException("Manager loop node has no tasks: {$node->id}");
            }
            
            // Limit to max agents
            $tasks = array_slice($tasks, 0, $maxAgents);
            
            // Execute tasks in parallel (simulated - PHP is sync)
            $results = [];
            foreach ($tasks as $task) {
                $taskId = $task['id'] ?? count($results);
                $taskPrompt = $task['prompt'] ?? $task;
                
                // If we have an LLM, use it
                $result = 'No LLM configured for subagent';
                if ($this->session) {
                    $this->session->context = $context;
                    $agentResult = $this->session->submit($taskPrompt);
                    $result = $agentResult['response'] ?? 'No response';
                }
                
                $results[$taskId] = [
                    'prompt' => $taskPrompt,
                    'result' => $result,
                ];
            }
            
            return [
                'context' => array_merge($context, [
                    'manager_agent_type' => $agentType,
                    'manager_task_count' => count($tasks),
                    'manager_results' => $results,
                    "manager_results_{$node->id}" => $results,
                ]),
                'next' => null,
            ];
        };
        
        // Start node handler
        $this->handlers['Mdiamond'] = function(Node $node, array $context, Graph $graph) {
            return [
                'context' => $context,
                'next' => null,
            ];
        };
    }
    
    /**
     * Evaluate a condition against the context.
     * Supports: context variable checks, comparisons, boolean expressions.
     */
    protected function evaluateCondition(string $condition, array $context): bool
    {
        // Empty condition = true (fall through)
        if (empty(trim($condition))) {
            return true;
        }
        
        // Direct context key check: "variable_name" or "!variable_name"
        if (preg_match('/^(!)?([a-zA-Z_][a-zA-Z0-9_]*)$/', $condition, $matches)) {
            $negate = $matches[1] === '!';
            $key = $matches[2];
            $exists = array_key_exists($key, $context) && !empty($context[$key]);
            return $negate ? !$exists : $exists;
        }
        
        // Comparison: "variable op value"
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $condition, $matches)) {
            $key = $matches[1];
            $op = $matches[2];
            $value = trim($matches[3]);
            $contextValue = $context[$key] ?? null;
            
            // Try to parse the comparison value
            if ($value === 'true') $compareTo = true;
            elseif ($value === 'false') $compareTo = false;
            elseif ($value === 'null') $compareTo = null;
            elseif (is_numeric($value)) $compareTo = (float)$value;
            else $compareTo = trim($value, '"\' ');
            
            return match($op) {
                '==' => $contextValue == $compareTo,
                '!=' => $contextValue != $compareTo,
                '>' => $contextValue > $compareTo,
                '<' => $contextValue < $compareTo,
                '>=' => $contextValue >= $compareTo,
                '<=' => $contextValue <= $compareTo,
                default => false,
            };
        }
        
        // Boolean expression: "cond1 AND cond2" or "cond1 OR cond2"
        if (str_contains($condition, ' AND ')) {
            $parts = array_map(fn($p) => $this->evaluateCondition(trim($p), $context), explode(' AND ', $condition));
            return !in_array(false, $parts);
        }
        
        if (str_contains($condition, ' OR ')) {
            $parts = array_map(fn($p) => $this->evaluateCondition(trim($p), $context), explode(' OR ', $condition));
            return in_array(true, $parts);
        }
        
        // Default: treat as truthy if context has values
        return !empty($condition);
    }
    
    /**
     * Provide human approval for a waiting node.
     */
    public function provideApproval(string $nodeId, bool $approved): void
    {
        $this->context["approval_{$nodeId}"] = $approved ? 'approved' : 'rejected';
    }
    
    /**
     * Check if engine is waiting for human approval.
     */
    public function isWaitingForApproval(): bool
    {
        return isset($this->context['waiting_for_approval']) && $this->context['waiting_for_approval'] === true;
    }
    
    /**
     * Get the node ID waiting for approval.
     */
    public function getWaitingNodeId(): ?string
    {
        return $this->context['waiting_for_human'] ?? null;
    }
    
    /**
     * Execute a single branch in parallel execution.
     */
    protected function executeBranch(Graph $graph, string $startNodeId, array $context, string $parallelId): array
    {
        $currentNodeId = $startNodeId;
        $maxIterations = 100;
        $iteration = 0;
        $error = null;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            $node = $graph->getNode($currentNodeId);
            
            if (!$node) {
                throw new \RuntimeException("Node not found: {$currentNodeId}");
            }
            
            // Exit nodes end the branch
            if ($node->shape === 'Msquare') {
                return [
                    'context' => $context,
                    'iterations' => $iteration,
                    'error' => $error,
                ];
            }
            
            // Fan-in node ends this branch
            if ($node->shape === 'tripleoctagon') {
                return [
                    'context' => $context,
                    'iterations' => $iteration,
                    'error' => $error,
                ];
            }
            
            // Get handler
            $handler = $this->handlers[$node->shape] ?? $this->handlers['box'] ?? null;
            
            if (!$handler) {
                throw new \RuntimeException("No handler for shape: {$node->shape}");
            }
            
            // Execute handler (catch exceptions)
            try {
                $result = $handler($node, $context, $graph);
                $context = array_merge($context, $result['context'] ?? []);
            } catch (\Exception $e) {
                $error = $e->getMessage();
                $context = array_merge($context, ['branch_error' => $error]);
                break;
            }
            
            // Determine next node
            $nextNodeId = $result['next'] ?? null;
            
            if (!$nextNodeId) {
                $edges = $graph->getOutgoingEdges($currentNodeId);
                if (empty($edges)) {
                    // End of branch
                    break;
                }
                $nextNodeId = array_values($edges)[0]->to;
            }
            
            $currentNodeId = $nextNodeId;
        }
        
        return [
            'context' => $context,
            'iterations' => $iteration,
            'error' => $error,
        ];
    }
    
    /**
     * Find the fan-in node connected to all branches.
     */
    protected function findFanInNode(Graph $graph, array $branchNodeIds): ?string
    {
        // Find nodes that all branches converge to
        $candidateTargets = [];
        
        foreach ($branchNodeIds as $branchId) {
            $edges = $graph->getOutgoingEdges($branchId);
            $targets = array_map(fn($e) => $e->to, $edges);
            
            if (empty($candidateTargets)) {
                $candidateTargets = $targets;
            } else {
                // Keep only targets that appear in both
                $candidateTargets = array_intersect($candidateTargets, $targets);
            }
            
            if (empty($candidateTargets)) {
                break;
            }
        }
        
        // Return first candidate that's a fan-in node
        foreach ($candidateTargets as $target) {
            $node = $graph->getNode($target);
            if ($node && $node->shape === 'tripleoctagon') {
                return $target;
            }
        }
        
        return null;
    }
    
    /**
     * Execute a tool by name with arguments.
     */
    protected function executeTool(string $tool, array $args): string
    {
        return match($tool) {
            'read_file', 'read' => $this->toolReadFile($args),
            'list_dir', 'ls', 'dir' => $this->toolListDir($args),
            'search', 'grep', 'find' => $this->toolSearch($args),
            'bash', 'shell', 'exec' => $this->toolBash($args),
            'write_file', 'write' => $this->toolWriteFile($args),
            'glob', 'find_files' => $this->toolGlob($args),
            default => "Unknown tool: {$tool}",
        };
    }
    
    /**
     * Substitute context variables in a string.
     * Variables are in {{variable_name}} format.
     */
    protected function substituteContext(string $value, array $context): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($context) {
            $key = trim($matches[1]);
            return $context[$key] ?? $matches[0];
        }, $value);
    }
    
    protected function toolReadFile(array $args): string
    {
        $path = $args['path'] ?? $args['file'] ?? null;
        if (!$path) return "Error: No path specified";
        if (!file_exists($path)) return "Error: File not found: {$path}";
        $content = file_get_contents($path);
        $maxLen = $args['max'] ?? 2000;
        if (strlen($content) > $maxLen) {
            return substr($content, 0, $maxLen) . "\n... (truncated)";
        }
        return $content;
    }
    
    protected function toolListDir(array $args): string
    {
        $path = $args['path'] ?? $args['dir'] ?? '.';
        if (!is_dir($path)) return "Error: Not a directory: {$path}";
        $files = scandir($path);
        return implode("\n", array_filter($files, fn($f) => !str_starts_with($f, '.')));
    }
    
    protected function toolSearch(array $args): string
    {
        $query = $args['query'] ?? $args['term'] ?? '';
        $path = $args['path'] ?? $args['dir'] ?? '.';
        if (empty($query)) return "Error: No search query";
        
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
            if (in_array($ext, ['php', 'js', 'txt', 'md', 'json'])) {
                $content = file_get_contents($file->getPathname());
                if (stripos($content, $query) !== false) {
                    $results[] = $file->getPathname();
                }
            }
        }
        
        return empty($results) ? "No matches found" : implode("\n", array_slice($results, 0, 10));
    }
    
    protected function toolBash(array $args): string
    {
        $cmd = $args['cmd'] ?? $args['command'] ?? null;
        if (!$cmd) return "Error: No command specified";
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return "Error (code {$returnCode}): " . implode("\n", $output);
        }
        
        return implode("\n", $output);
    }
    
    protected function toolWriteFile(array $args): string
    {
        $path = $args['path'] ?? $args['file'] ?? null;
        $content = $args['content'] ?? '';
        
        if (!$path) return "Error: No path specified";
        
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($path, $content);
        return "Written to: {$path}";
    }
    
    protected function toolGlob(array $args): string
    {
        $pattern = $args['pattern'] ?? $args['glob'] ?? '*';
        $path = $args['path'] ?? $args['dir'] ?? '.';
        
        $files = glob($path . '/' . $pattern);
        return implode("\n", $files);
    }
}

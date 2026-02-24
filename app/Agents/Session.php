<?php

namespace App\Agents;

use App\LLM\ClientInterface;

class Session
{
    public array $history = [];
    public array $context = [];
    protected array $tools = [];
    
    public function __construct(
        protected ClientInterface $llm,
    ) {}
    
    /**
     * Set available tools for this session.
     */
    public function setTools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }
    
    /**
     * Submit input and process through the agent loop.
     */
    public function submit(string $input, int $maxRounds = 10): array
    {
        // Start fresh for this submission
        $this->history = [];
        $this->history[] = ['role' => 'user', 'content' => $input];
        
        for ($round = 0; $round < $maxRounds; $round++) {
            // Build messages from history
            $messages = $this->buildMessages();
            
            // Get LLM response
            $response = $this->llm->complete('', [
                'messages' => $messages,
            ]);
            
            $this->history[] = ['role' => 'assistant', 'content' => $response];
            
            // Check if response contains tool calls (simple pattern matching)
            $toolCalls = $this->parseToolCalls($response);
            
            if (empty($toolCalls)) {
                // No tools - natural completion
                return [
                    'status' => 'completed',
                    'response' => $response,
                    'rounds' => $round + 1,
                ];
            }
            
            // Execute tools and append results
            $toolResults = $this->executeTools($toolCalls);
            
            // Add tool result messages
            foreach ($toolResults as $result) {
                $this->history[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['tool'],
                    'content' => $result['result'],
                ];
            }
            
            // Continue to next round - model will see tool results
        }
        
        return [
            'status' => 'max_rounds',
            'rounds' => $maxRounds,
            'history' => $this->history,
        ];
    }
    
    /**
     * Build messages array from history for LLM API.
     */
    protected function buildMessages(): array
    {
        $messages = [];
        
        // System message - MUST use tools when requested
        $systemContent = <<<'EOT'
You are a coding agent that executes commands using tools.

CRITICAL: When asked to run a shell command, output ONLY the tool call in this format:
bash(command="your command here")

After running a command and seeing the result, simply respond with a summary. Do NOT repeat the command.
EOT;
        
        if (!empty($this->tools)) {
            $systemContent .= "\n\nAvailable tools:\n";
            foreach ($this->tools as $name => $desc) {
                $systemContent .= "- {$name}: {$desc}\n";
            }
        }
        
        if (!empty($this->context)) {
            $systemContent .= "\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT);
        }
        
        $messages[] = ['role' => 'system', 'content' => $systemContent];
        
        // Add conversation history (skip first user message if already in system)
        foreach ($this->history as $message) {
            $role = $message['role'];
            $content = $message['content'];
            
            if ($role === 'user') {
                $messages[] = ['role' => 'user', 'content' => $content];
            } elseif ($role === 'assistant') {
                $messages[] = ['role' => 'assistant', 'content' => $content];
            } elseif ($role === 'tool') {
                $messages[] = [
                    'role' => 'tool',
                    'content' => $content,
                ];
            }
        }
        
        return $messages;
    }
    
    /**
     * Parse tool calls from response.
     */
    protected function parseToolCalls(string $response): array
    {
        $calls = [];
        
        // Pattern 1: gpt-oss-20b format: to=functions.bash or to=bash
        if (preg_match_all('/to=(\w+).*?message\|>([^<]+)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toolName = $match[1];
                $command = trim($match[2]);
                // Remove JSON wrapper if present
                if (preg_match('/"command"\s*:\s*"([^"]+)"/', $command, $cmdMatch)) {
                    $command = $cmdMatch[1];
                }
                $calls[] = [
                    'tool' => $toolName,
                    'args' => ['command' => $command],
                ];
            }
        }
        
        // Pattern 2: bash("command") or bash(command="...") format
        if (preg_match_all('/bash\(\s*(["\']?)([^"\']+)\1\s*\)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $command = $match[2];
                // Skip if it's just a placeholder
                if (empty($command) || $command === '...' || str_contains($command, '...')) {
                    continue;
                }
                $calls[] = [
                    'tool' => 'bash',
                    'args' => ['command' => $command],
                ];
            }
        }
        
        // Pattern 2b: bash(command="...") format with named argument
        if (preg_match_all('/bash\(\s*command\s*=\s*["\']([^"\']+)["\']\s*\)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $command = $match[1];
                // Skip if already captured
                if (in_array('bash', array_column($calls, 'tool'))) {
                    continue;
                }
                $calls[] = [
                    'tool' => 'bash',
                    'args' => ['command' => $command],
                ];
            }
        }
        
        // Pattern 3: read_file(path="...") format
        if (preg_match_all('/(\w+)\(([^)]+)\)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toolName = $match[1];
                $argsStr = $match[2];
                
                // Skip if it looks like regular text
                if (in_array($toolName, ['The', 'This', 'You', 'I', 'Sure', 'Okay', 'Let', 'Here'])) {
                    continue;
                }
                
                // Skip if already captured
                if (in_array($toolName, array_column($calls, 'tool'))) {
                    continue;
                }
                
                // Skip bash - handled by Pattern 2
                if ($toolName === 'bash') {
                    continue;
                }
                
                // Parse arguments
                $args = $this->parseArguments($argsStr);
                
                $calls[] = [
                    'tool' => $toolName,
                    'args' => $args,
                ];
            }
        }
        
        return $calls;
    }
    
    /**
     * Parse tool arguments from string.
     */
    protected function parseArguments(string $argsStr): array
    {
        $args = [];
        
        // Match key="value" or key=value
        preg_match_all('/(\w+)=("[^"]*"|\S+)/', $argsStr, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            $value = trim($value, '"');
            $args[$key] = $value;
        }
        
        return $args;
    }
    
    /**
     * Execute tool calls.
     */
    protected function executeTools(array $toolCalls): array
    {
        $results = [];
        
        foreach ($toolCalls as $call) {
            $tool = $call['tool'];
            $args = $call['args'];
            
            // Simple built-in tools
            $result = match($tool) {
                'read_file' => $this->toolReadFile($args),
                'list_dir' => $this->toolListDir($args),
                'search' => $this->toolSearch($args),
                'bash' => $this->toolBash($args),
                default => "Unknown tool: {$tool}",
            };
            
            $results[] = [
                'tool' => $tool,
                'result' => $result,
            ];
        }
        
        return $results;
    }
    
    protected function toolBash(array $args): string
    {
        $command = $args['command'] ?? ($args['arguments']['command'] ?? null);
        
        if (!$command) {
            return "Error: No command specified";
        }
        
        $output = [];
        $returnCode = 0;
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return "Error: Command failed with code $returnCode";
        }
        
        return implode("\n", $output);
    }
    
    protected function toolReadFile(array $args): string
    {
        $path = $args['path'] ?? $args['file'] ?? null;
        
        if (!$path) {
            return "Error: No path specified";
        }
        
        if (!file_exists($path)) {
            return "Error: File not found: {$path}";
        }
        
        $content = file_get_contents($path);
        $maxLen = 2000;
        
        if (strlen($content) > $maxLen) {
            return substr($content, 0, $maxLen) . "\n... (truncated)";
        }
        
        return $content;
    }
    
    protected function toolListDir(array $args): string
    {
        $path = $args['path'] ?? $args['dir'] ?? '.';
        
        if (!is_dir($path)) {
            return "Error: Not a directory: {$path}";
        }
        
        $files = scandir($path);
        return implode("\n", array_filter($files, fn($f) => !str_starts_with($f, '.')));
    }
    
    protected function toolSearch(array $args): string
    {
        $query = $args['query'] ?? $args['term'] ?? '';
        $path = $args['path'] ?? '.';
        
        if (empty($query)) {
            return "Error: No search query";
        }
        
        // Simple grep implementation
        $results = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            if (str_contains($file->getFilename(), '.')) {
                $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                if (in_array($ext, ['php', 'js', 'txt', 'md'])) {
                    $content = file_get_contents($file->getPathname());
                    if (stripos($content, $query) !== false) {
                        $results[] = $file->getPathname();
                    }
                }
            }
        }
        
        return empty($results) 
            ? "No matches found" 
            : implode("\n", array_slice($results, 0, 10));
    }
}

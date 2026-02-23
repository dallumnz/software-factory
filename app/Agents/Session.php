<?php

namespace App\Agents;

use App\LLM\ClientInterface;

class Session
{
    public array $history = [];
    public array $context = [];
    
    public function __construct(
        protected ClientInterface $llm,
    ) {}
    
    /**
     * Submit input and process through the agent loop.
     */
    public function submit(string $input, int $maxRounds = 10): array
    {
        $this->history[] = ['role' => 'user', 'content' => $input];
        
        for ($round = 0; $round < $maxRounds; $round++) {
            // Build prompt from history
            $prompt = $this->buildPrompt();
            
            // Get LLM response
            $response = $this->llm->complete($prompt);
            
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
            
            $this->history[] = [
                'role' => 'tool',
                'content' => json_encode($toolResults),
            ];
        }
        
        return [
            'status' => 'max_rounds',
            'rounds' => $maxRounds,
            'history' => $this->history,
        ];
    }
    
    /**
     * Build prompt from history.
     */
    protected function buildPrompt(): string
    {
        $prompt = "You are a coding agent. ";
        
        if (!empty($this->context)) {
            $prompt .= "Context: " . json_encode($this->context) . "\n\n";
        }
        
        foreach ($this->history as $message) {
            $role = $message['role'];
            $content = $message['content'];
            
            if ($role === 'user') {
                $prompt .= "User: {$content}\n";
            } elseif ($role === 'assistant') {
                $prompt .= "Assistant: {$content}\n";
            } elseif ($role === 'tool') {
                $prompt .= "Tool result: {$content}\n";
            }
        }
        
        $prompt .= "Assistant: ";
        
        return $prompt;
    }
    
    /**
     * Parse tool calls from response.
     * Simple implementation - looks for TOOL_NAME(args) pattern.
     */
    protected function parseToolCalls(string $response): array
    {
        $calls = [];
        
        // Match patterns like: read_file(path="app/Http/Controllers/Test.php")
        if (preg_match_all('/(\w+)\(([^)]+)\)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toolName = $match[1];
                $argsStr = $match[2];
                
                // Skip if it looks like regular text
                if (in_array($toolName, ['The', 'This', 'You', 'I', 'Sure', 'Okay'])) {
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
                default => "Unknown tool: {$tool}",
            };
            
            $results[] = [
                'tool' => $tool,
                'result' => $result,
            ];
        }
        
        return $results;
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

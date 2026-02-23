<?php

namespace App\LLM;

use Exception;

class OpenCodeClient implements ClientInterface
{
    protected string $model;
    protected array $options;
    
    public function __construct(
        string $model = 'default',
        array $options = []
    ) {
        $this->model = $model;
        $this->options = $options;
    }
    
    /**
     * Send a prompt and get a text response via opencode CLI.
     */
    public function complete(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $timeout = $options['timeout'] ?? 60;
        
        // Build the opencode command
        // Note: opencode doesn't have a simple --complete flag, this is a placeholder
        // In practice you'd use the API or a different approach
        $cmd = sprintf(
            'echo %s | opencode -m %s 2>&1',
            escapeshellarg($prompt),
            escapeshellarg($model)
        );
        
        $output = [];
        $returnCode = 0;
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('OpenCode command failed: ' . implode("\n", $output));
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Stream a response.
     */
    public function stream(string $prompt, callable $onChunk, array $options = []): void
    {
        $model = $options['model'] ?? $this->model;
        
        // For streaming, we'd need to use proc_open with pipes
        // This is a simplified version
        $response = $this->complete($prompt, $options);
        $onChunk($response);
    }
}

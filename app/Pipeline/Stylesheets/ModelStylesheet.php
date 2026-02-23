<?php

namespace App\Pipeline\Stylesheets;

/**
 * Model Stylesheets
 * 
 * Defines model-specific settings like prompts, tools, and LLM parameters.
 * Allows different behavior for different models (e.g., coding vs reasoning).
 */
class ModelStylesheet
{
    protected array $stylesheets = [];
    
    public function __construct()
    {
        $this->registerDefaults();
    }
    
    /**
     * Register default stylesheets for common models.
     */
    protected function registerDefaults(): void
    {
        // Qwen3 - good for coding
        $this->register('qwen3-14b', [
            'temperature' => 0.3,
            'max_tokens' => 4096,
            'prompt_prefix' => "You are an expert PHP/Laravel developer. Write clean, modern PHP code.\n\n",
            'prompt_suffix' => "\n\nProvide the code only, without explanation unless asked. Prefer PHP Laravel patterns.",
        ]);
        
        $this->register('qwen/qwen3-coder-30b', [
            'temperature' => 0.3,
            'max_tokens' => 4096,
            'prompt_prefix' => "You are an expert programmer. Write clean, efficient code.\n\n",
            'prompt_suffix' => "\n\nProvide the code only.",
        ]);
        
        // Reasoning models
        $this->register('deepseek-r1', [
            'temperature' => 0.6,
            'max_tokens' => 8192,
            'prompt_prefix' => "Think through this step by step. Show your reasoning.\n\n",
            'prompt_suffix' => "\n\nExplain your reasoning process.",
        ]);
        
        // Granite - enterprise/analysis
        $this->register('ibm/granite-4-h-tiny', [
            'temperature' => 0.5,
            'max_tokens' => 4096,
            'prompt_prefix' => "You are an enterprise AI assistant. Provide thorough, accurate responses.\n\n",
            'prompt_suffix' => "",
        ]);
        
        // Default fallback
        $this->register('default', [
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'prompt_prefix' => "",
            'prompt_suffix' => "",
        ]);
    }
    
    /**
     * Register a stylesheet for a model.
     */
    public function register(string $model, array $config): void
    {
        $this->stylesheets[$model] = $config;
    }
    
    /**
     * Get stylesheet for a model (falls back to default).
     */
    public function get(string $model): array
    {
        // Exact match
        if (isset($this->stylesheets[$model])) {
            return $this->stylesheets[$model];
        }
        
        // Try prefix match (e.g., "qwen/qwen3-coder-30b" matches "qwen3-14b" if we had one)
        foreach ($this->stylesheets as $pattern => $config) {
            if (str_contains($model, $pattern) || str_contains($pattern, $model)) {
                return $config;
            }
        }
        
        // Default
        return $this->stylesheets['default'] ?? [
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'prompt_prefix' => '',
            'prompt_suffix' => '',
        ];
    }
    
    /**
     * Apply stylesheet to a prompt.
     */
    public function applyPrompt(string $model, string $prompt): string
    {
        $style = $this->get($model);
        
        $prefix = $style['prompt_prefix'] ?? '';
        $suffix = $style['prompt_suffix'] ?? '';
        
        return $prefix . $prompt . $suffix;
    }
    
    /**
     * Get LLM options for a model.
     */
    public function getLLMOptions(string $model): array
    {
        $style = $this->get($model);
        
        return [
            'temperature' => $style['temperature'] ?? 0.7,
            'max_tokens' => $style['max_tokens'] ?? 2048,
        ];
    }
    
    /**
     * List all registered stylesheets.
     */
    public function list(): array
    {
        return $this->stylesheets;
    }
}

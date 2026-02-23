<?php

namespace App\LLM;

use App\LLM\ClientInterface;
use App\LLM\OllamaClient;
use App\LLM\LMStudioClient;

class ProviderManager
{
    /** @var array<string, array{client: ClientInterface, priority: int, enabled: bool}> */
    protected array $providers = [];
    
    /** @var string|null */
    protected ?string $defaultProvider = null;
    
    /**
     * Register a provider.
     */
    public function register(string $name, ClientInterface $client, int $priority = 100): self
    {
        $this->providers[$name] = [
            'client' => $client,
            'priority' => $priority,
            'enabled' => true,
        ];
        
        if ($this->defaultProvider === null) {
            $this->defaultProvider = $name;
        }
        
        return $this;
    }
    
    /**
     * Register the standard providers (local-first).
     */
    public function registerStandardProviders(): self
    {
        // Try LM Studio first (often faster/better local)
        $this->register('lmstudio', new LMStudioClient('http://localhost:1234'), 100);
        
        // Then Ollama
        $this->register('ollama', new OllamaClient('http://localhost:11434'), 90);
        
        return $this;
    }
    
    /**
     * Enable or disable a provider.
     */
    public function setEnabled(string $name, bool $enabled): self
    {
        if (isset($this->providers[$name])) {
            $this->providers[$name]['enabled'] = $enabled;
        }
        return $this;
    }
    
    /**
     * Get a client by name.
     */
    public function get(string $name): ?ClientInterface
    {
        return $this->providers[$name]['client'] ?? null;
    }
    
    /**
     * Get the best available client (highest priority enabled).
     */
    public function getBest(): ?ClientInterface
    {
        $enabled = array_filter($this->providers, fn($p) => $p['enabled']);
        
        if (empty($enabled)) {
            return null;
        }
        
        // Sort by priority (descending)
        uasort($enabled, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        return reset($enabled)['client'] ?? null;
    }
    
    /**
     * Get the first working client by testing each enabled provider.
     */
    public function getWorking(?string $prefer = null): ?ClientInterface
    {
        $enabled = array_filter($this->providers, fn($p) => $p['enabled']);
        
        if (empty($enabled)) {
            return null;
        }
        
        // Sort by priority
        uasort($enabled, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        // If we have a preference, try that first
        if ($prefer && isset($enabled[$prefer]) && $enabled[$prefer]['enabled']) {
            if ($this->testConnection($enabled[$prefer]['client'])) {
                return $enabled[$prefer]['client'];
            }
        }
        
        // Try each provider in priority order
        foreach ($enabled as $name => $config) {
            if ($this->testConnection($config['client'])) {
                return $config['client'];
            }
        }
        
        return null;
    }
    
    /**
     * Test if a client is working.
     */
    protected function testConnection(ClientInterface $client): bool
    {
        try {
            // Simple test - try a minimal completion
            $client->complete('ping', ['timeout' => 5]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all provider names.
     */
    public function list(): array
    {
        return array_keys($this->providers);
    }
    
    /**
     * Get provider status (enabled/disabled).
     */
    public function status(): array
    {
        $status = [];
        foreach ($this->providers as $name => $config) {
            $status[$name] = [
                'enabled' => $config['enabled'],
                'priority' => $config['priority'],
                'available' => $this->testConnection($config['client']),
            ];
        }
        return $status;
    }
    
    /**
     * Set the default provider.
     */
    public function setDefault(string $name): self
    {
        if (isset($this->providers[$name])) {
            $this->defaultProvider = $name;
        }
        return $this;
    }
    
    /**
     * Get the default provider client.
     */
    public function getDefault(): ?ClientInterface
    {
        if ($this->defaultProvider && isset($this->providers[$this->defaultProvider])) {
            return $this->providers[$this->defaultProvider]['client'];
        }
        return $this->getBest();
    }
}

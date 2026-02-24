<?php

namespace App\LLM;

use App\LLM\ClientInterface;

class OllamaClient implements ClientInterface
{
    public function __construct(
        protected string $baseUrl = 'http://localhost:11434',
        public string $model = 'llama3.2',
    ) {}
    
    public function complete(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        
        // Use chat endpoint if messages provided, otherwise use generate
        $messages = $options['messages'] ?? [];
        
        if (!empty($messages)) {
            // Use /api/chat for message-based conversations
            $response = $this->request('/api/chat', [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ]);
            return $response['message']['content'] ?? '';
        }
        
        // Fall back to /api/generate for simple prompts
        $response = $this->request('/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
        ]);
        
        return $response['response'] ?? '';
    }
    
    public function stream(string $prompt, callable $onChunk, array $options = []): void
    {
        $model = $options['model'] ?? $this->model;
        
        $this->requestStream('/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => true,
        ], $onChunk);
    }
    
    protected function request(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Ollama request failed: {$error}");
        }
        
        return json_decode($response, true) ?? [];
    }
    
    protected function requestStream(string $endpoint, array $data, callable $onChunk): void
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use ($onChunk) {
            $lines = explode("\n", trim($chunk));
            foreach ($lines as $line) {
                if (empty($line)) continue;
                $data = json_decode($line, true);
                if (isset($data['response'])) {
                    $onChunk($data['response']);
                }
            }
            return strlen($chunk);
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Ollama stream failed: {$error}");
        }
    }
}

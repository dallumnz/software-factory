<?php

namespace App\LLM;

use App\LLM\ClientInterface;

class LMStudioClient implements ClientInterface
{
    public function __construct(
        protected string $baseUrl = 'http://localhost:1234/api/v0',
        public string $model = 'qwen3-14b',
    ) {}
    
    public function complete(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        
        $response = $this->request('/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'stream' => false,
        ]);
        
        return $response['choices'][0]['message']['content'] ?? '';
    }
    
    public function stream(string $prompt, callable $onChunk, array $options = []): void
    {
        $model = $options['model'] ?? $this->model;
        
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'stream' => true,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use ($onChunk) {
            $lines = explode("\n", trim($chunk));
            foreach ($lines as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]') continue;
                    $json = json_decode($data, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $onChunk($json['choices'][0]['delta']['content']);
                    }
                }
            }
            return strlen($chunk);
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("LM Studio request failed: {$error}");
        }
    }
    
    protected function request(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 second timeout for LLM responses
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("LM Studio request failed: {$error}");
        }
        
        return json_decode($response, true) ?? [];
    }
}

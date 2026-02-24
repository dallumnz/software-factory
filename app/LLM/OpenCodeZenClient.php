<?php

namespace App\LLM;

use App\LLM\ClientInterface;

class OpenCodeZenClient implements ClientInterface
{
    protected string $apiKey;
    
    public function __construct(
        protected string $baseUrl = 'https://opencode.ai/zen/v1',
        public string $model = 'kimi-k2.5',
        string $apiKey = ''
    ) {
        // Try to load API key from auth file if not provided
        if (empty($apiKey)) {
            $authFile = $_SERVER['HOME'] . '/.local/share/opencode/auth.json';
            if (file_exists($authFile)) {
                $auth = json_decode(file_get_contents($authFile), true);
                $apiKey = $auth['opencode']['key'] ?? '';
            }
        }
        $this->apiKey = $apiKey;
    }
    
    public function complete(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $maxTokens = $options['max_tokens'] ?? 4096;
        
        // Build messages - either from history option or single prompt
        $messages = $options['messages'] ?? [];
        
        if (empty($messages)) {
            // Default: wrap prompt as user message
            $messages = [
                ['role' => 'user', 'content' => $prompt]
            ];
        }
        
        $response = $this->request('/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => $maxTokens,
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
            'messages' => $options['messages'] ?? [['role' => 'user', 'content' => $prompt]],
            'stream' => true,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
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
            throw new \RuntimeException("OpenCode Zen request failed: {$error}");
        }
    }
    
    protected function request(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("OpenCode Zen request failed: {$error}");
        }
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("OpenCode Zen API error (HTTP {$httpCode}): {$response}");
        }
        
        return json_decode($response, true) ?? [];
    }
}

<?php

namespace App\LLM;

interface ClientInterface
{
    /**
     * Send a prompt and get a text response.
     * 
     * @param string $prompt The prompt/content to send
     * @param array $options Optional: model, max_tokens, messages (array of {role, content})
     * @return string The model's response
     */
    public function complete(string $prompt, array $options = []): string;
    
    /**
     * Stream a response (yields chunks).
     */
    public function stream(string $prompt, callable $onChunk, array $options = []): void;
}

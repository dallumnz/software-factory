<?php

namespace App\LLM;

interface ClientInterface
{
    /**
     * Send a prompt and get a text response.
     */
    public function complete(string $prompt, array $options = []): string;
    
    /**
     * Stream a response (yields chunks).
     */
    public function stream(string $prompt, callable $onChunk, array $options = []): void;
}

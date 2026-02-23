<?php

use App\LLM\OllamaClient;
use App\LLM\ClientInterface;
use App\Agents\Session;

it('creates a session with LLM client', function () {
    $client = new OllamaClient();
    $session = new Session($client);
    
    expect($session)->toBeInstanceOf(Session::class);
});

it('adds user input to history with mocked client', function () {
    // Mock the LLM client
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string {
            return 'No tools needed';
        }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    
    $session = new Session($mockClient);
    $result = $session->submit('Hello');
    
    expect($session->history[0]['role'])->toBe('user');
    expect($session->history[0]['content'])->toBe('Hello');
});

it('parses simple tool calls from response', function () {
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return ''; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    $session = new Session($mockClient);
    
    // Use reflection to test parseToolCalls
    $response = 'I will read the file. read_file(path="test.php")';
    
    // Test via the executeTools path
    $reflection = new \ReflectionClass($session);
    $method = $reflection->getMethod('parseToolCalls');
    
    $calls = $method->invoke($session, $response);
    
    expect($calls)->toHaveCount(1);
    expect($calls[0]['tool'])->toBe('read_file');
    expect($calls[0]['args']['path'])->toBe('test.php');
});

it('parses multiple tool calls', function () {
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return ''; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    $session = new Session($mockClient);
    
    $reflection = new \ReflectionClass($session);
    $method = $reflection->getMethod('parseToolCalls');
    
    $response = 'read_file(path="a.php") then list_dir(path=".")';
    $calls = $method->invoke($session, $response);
    
    expect($calls)->toHaveCount(2);
});

it('reads a file with tool', function () {
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return ''; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    $session = new Session($mockClient);
    
    $reflection = new \ReflectionClass($session);
    $method = $reflection->getMethod('toolReadFile');
    
    // Read this file itself
    $result = $method->invoke($session, ['path' => __FILE__]);
    
    expect($result)->toContain('use App\LLM\OllamaClient');
});

it('lists directory with tool', function () {
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return ''; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    $session = new Session($mockClient);
    
    $reflection = new \ReflectionClass($session);
    $method = $reflection->getMethod('toolListDir');
    
    $result = $method->invoke($session, ['path' => dirname(__DIR__) . '/Pipeline']);
    
    expect($result)->toContain('DOTParserTest.php');
});

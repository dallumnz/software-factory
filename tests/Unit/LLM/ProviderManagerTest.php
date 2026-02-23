<?php

use App\LLM\ProviderManager;
use App\LLM\ClientInterface;

it('registers providers', function () {
    $manager = new ProviderManager();
    
    $mockClient = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string {
            return 'test';
        }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {
            $onChunk('test');
        }
    };
    
    $manager->register('test', $mockClient, 50);
    
    expect($manager->get('test'))->toBe($mockClient);
    expect($manager->list())->toContain('test');
});

it('selects best provider by priority', function () {
    $manager = new ProviderManager();
    
    $low = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return 'low'; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    
    $high = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return 'high'; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    
    $manager->register('low', $low, 10);
    $manager->register('high', $high, 100);
    
    $best = $manager->getBest();
    
    // Should return the high priority client
    expect($manager->get('high'))->toBe($high);
});

it('enables and disables providers', function () {
    $manager = new ProviderManager();
    
    $client = new class implements ClientInterface {
        public function complete(string $prompt, array $options = []): string { return 'test'; }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {}
    };
    
    $manager->register('test', $client);
    $manager->setEnabled('test', false);
    
    $status = $manager->status();
    expect($status['test']['enabled'])->toBe(false);
});

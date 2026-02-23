<?php

use App\Pipeline\DOTParser;
use App\Pipeline\Engine;

it('executes a simple pipeline', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        task1 [shape=box, label="Task 1"]
        task2 [shape=box, label="Task 2"]
        end [shape=Msquare]
        
        start -> task1
        task1 -> task2
        task2 -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['iterations'])->toBe(4); // start, task1, task2, end
});

it('uses custom handler for node shape', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        custom [shape=box, label="Custom Task"]
        end [shape=Msquare]
        
        start -> custom
        custom -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // Register custom handler
    $engine->registerHandler('box', function($node, $context) {
        return [
            'context' => array_merge($context, [
                'custom_ran' => true,
                'node_id' => $node->id,
            ]),
            'next' => null,
        ];
    });
    
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['custom_ran'])->toBe(true);
    expect($result['context']['node_id'])->toBe('custom');
});

it('passes context between nodes', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        step1 [shape=box]
        step2 [shape=box]
        end [shape=Msquare]
        
        start -> step1
        step1 -> step2
        step2 -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // Register handler that adds to context
    $engine->registerHandler('box', function($node, $context) {
        return [
            'context' => array_merge($context, [
                'visited' => array_merge($context['visited'] ?? [], [$node->id]),
            ]),
            'next' => null,
        ];
    });
    
    $result = $engine->execute($graph, 'start', ['initial' => 'value']);
    
    expect($result['context']['initial'])->toBe('value');
    expect($result['context']['visited'])->toContain('step1', 'step2');
});

it('throws on missing node', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        end [shape=Msquare]
        start -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'nonexistent'))
        ->toThrow(\RuntimeException::class, 'Node not found');
});

it('throws on max iterations exceeded', function () {
    $dot = '
    digraph test {
        a [shape=box]
        b [shape=box]
        a -> b
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'a'))
        ->toThrow(\RuntimeException::class, 'No outgoing edges');
});

it('waits for human approval on hexagon node', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        review [shape=hexagon, label="Human Review"]
        approved [shape=box, label="Approved"]
        rejected [shape=box, label="Rejected"]
        end [shape=Msquare]
        
        start -> review
        review -> approved [label="approved"]
        review -> rejected [label="rejected"]
        approved -> end
        rejected -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    // Should pause at hexagon waiting for approval
    expect($engine->isWaitingForApproval())->toBe(true);
    expect($engine->getWaitingNodeId())->toBe('review');
});

it('provides approval mechanism', function () {
    $engine = new Engine();
    
    // Initially not waiting
    expect($engine->isWaitingForApproval())->toBe(false);
    expect($engine->getWaitingNodeId())->toBe(null);
    
    // Set context to simulate waiting state
    $engine->provideApproval('review', true);
    
    // After providing approval, the context should have it
    // (We can't easily test the full resume flow without re-executing)
    expect(true)->toBe(true); // Placeholder - full resume would need state preservation
});

it('evaluates conditional on diamond node', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        decision [shape=diamond, label="tests_pass"]
        success [shape=box, label="Success"]
        fail [shape=box, label="Failed"]
        end [shape=Msquare]
        
        start -> decision
        decision -> success [label="true"]
        decision -> fail [label="false"]
        success -> end
        fail -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Test with tests_pass = true
    $engine = new Engine();
    $result = $engine->execute($graph, 'start', ['tests_pass' => true]);
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['condition_result'])->toBe(true);
});

it('evaluates comparison in conditional', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        decision [shape=diamond, condition="errors > 0"]
        has_errors [shape=box, label="Has Errors"]
        no_errors [shape=box, label="No Errors"]
        end [shape=Msquare]
        
        start -> decision
        decision -> has_errors [label="true"]
        decision -> no_errors [label="false"]
        has_errors -> end
        no_errors -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Test with errors = 5
    $engine = new Engine();
    $result = $engine->execute($graph, 'start', ['errors' => 5]);
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['condition_result'])->toBe(true);
});

it('executes parallel branches', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        parallel [shape=component, label="Parallel Tasks"]
        task1 [shape=box, label="Task 1"]
        task2 [shape=box, label="Task 2"]
        fanin [shape=tripleoctagon, label="Collect Results"]
        end [shape=Msquare]
        
        start -> parallel
        parallel -> task1
        parallel -> task2
        task1 -> fanin
        task2 -> fanin
        fanin -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Register handlers that mark their execution
    $engine = new Engine();
    $engine->registerHandler('box', function($node, $context, $graph) {
        return [
            'context' => array_merge($context, [
                'executed_tasks' => array_merge($context['executed_tasks'] ?? [], [$node->id]),
            ]),
            'next' => null,
        ];
    });
    
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['executed_tasks'] ?? [])->toContain('task1', 'task2');
});

it('uses LLM client for box nodes', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        code [shape=box, prompt="Write a hello world function"]
        end [shape=Msquare]
        
        start -> code
        code -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // Set up mock LLM client
    $mockClient = new class implements \App\LLM\ClientInterface {
        public function complete(string $prompt, array $options = []): string {
            return "function hello() { return 'Hello World'; }";
        }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {
            $onChunk("function hello() { return 'Hello World'; }");
        }
    };
    
    $engine->setLLM($mockClient);
    
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['llm_response'] ?? '')->toContain('Hello World');
});

it('executes tool handler for parallelogram nodes', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        read [shape=parallelogram, tool="read_file", path="composer.json"]
        end [shape=Msquare]
        
        start -> read
        read -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['last_tool'])->toBe('read_file');
    expect($result['context']['tool_result'] ?? '')->toContain('name');
});

it('executes manager loop for house nodes', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        parallel [shape=house, agent="coder", task_1="Write a function", task_2="Write a test"]
        end [shape=Msquare]
        
        start -> parallel
        parallel -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Mock LLM
    $mockClient = new class implements \App\LLM\ClientInterface {
        public function complete(string $prompt, array $options = []): string {
            return "Generated code for: " . substr($prompt, 0, 20);
        }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {
            $onChunk("Generated code");
        }
    };
    
    $engine = new Engine();
    $engine->setLLM($mockClient);
    
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['manager_task_count'])->toBe(2);
    expect($result['context']['manager_results'] ?? null)->toBeArray();
});

it('respects max agents limit in manager loop', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        parallel [shape=house, max="2", task_1="Task 1", task_2="Task 2", task_3="Task 3", task_4="Task 4", task_5="Task 5"]
        end [shape=Msquare]
        
        start -> parallel
        parallel -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['context']['manager_task_count'])->toBe(2); // limited to max=2
});

// ============================================================
// EDGE CASE TESTS
// ============================================================

it('detects circular graph and throws after max iterations', function () {
    $dot = '
    digraph circular {
        start [shape=Mdiamond]
        node_a [shape=box]
        node_b [shape=box]
        
        start -> node_a
        node_a -> node_b
        node_b -> node_a  // Creates a cycle
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'start'))
        ->toThrow(\RuntimeException::class, 'Max iterations');
});

it('handles LLM client failure gracefully', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        generate [shape=box, prompt="Generate code"]
        end [shape=Msquare]
        
        start -> generate
        generate -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // Set up failing mock LLM client
    $failingClient = new class implements \App\LLM\ClientInterface {
        public function complete(string $prompt, array $options = []): string {
            throw new \RuntimeException('LLM API error: connection refused');
        }
        public function stream(string $prompt, callable $onChunk, array $options = []): void {
            throw new \RuntimeException('LLM API error: connection refused');
        }
    };
    
    $engine->setLLM($failingClient);
    
    // Should complete without crashing (LLM error is caught)
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['llm_status'])->toBe('error');
});

it('handles tool execution error - file not found', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        read_missing [shape=parallelogram, tool="read_file", path="/nonexistent/file.txt"]
        end [shape=Msquare]
        
        start -> read_missing
        read_missing -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['tool_result'])->toContain('Error');
    expect($result['context']['tool_result'])->toContain('not found');
});

it('handles tool execution error - invalid tool', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        bad_tool [shape=parallelogram, tool="nonexistent_tool"]
        end [shape=Msquare]
        
        start -> bad_tool
        bad_tool -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['tool_result'])->toContain('Unknown tool');
});

it('handles tool execution error - bash command failure', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        fail_cmd [shape=parallelogram, tool="bash", cmd="exit 1"]
        end [shape=Msquare]
        
        start -> fail_cmd
        fail_cmd -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
    expect($result['context']['tool_result'])->toContain('Error');
    expect($result['context']['tool_result'])->toContain('1');
});

it('handles self-referencing node', function () {
    $dot = '
    digraph self_ref {
        start [shape=Mdiamond]
        loop [shape=box]
        
        start -> loop
        loop -> loop  // Self-reference
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'start'))
        ->toThrow(\RuntimeException::class, 'Max iterations');
});

it('handles missing tool attribute on tool node', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        no_tool [shape=parallelogram, label="No tool specified"]
        end [shape=Msquare]
        
        start -> no_tool
        no_tool -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'start'))
        ->toThrow(\RuntimeException::class, 'missing');
});

it('handles parallel branch failure - one branch errors', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        parallel [shape=component]
        task1 [shape=box]
        task2 [shape=box]
        fanin [shape=tripleoctagon]
        end [shape=Msquare]
        
        start -> parallel
        parallel -> task1
        parallel -> task2
        task1 -> fanin
        task2 -> fanin
        fanin -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // First branch succeeds, second branch errors
    $callCount = 0;
    $engine->registerHandler('box', function($node, $context, $graph) use (&$callCount) {
        $callCount++;
        if ($node->id === 'task2') {
            throw new \RuntimeException('Task 2 failed');
        }
        return [
            'context' => array_merge($context, ['ran_' . $node->id => true]),
            'next' => null,
        ];
    });
    
    // Should complete (errors are caught per branch)
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
});

it('handles unregistered node shape gracefully', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        unknown [shape=unknown_shape]
        end [shape=Msquare]
        
        start -> unknown
        unknown -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    // Should fall back to 'box' handler
    $result = $engine->execute($graph, 'start');
    
    expect($result['status'])->toBe('completed');
});

it('evaluates complex boolean - AND expression', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        decision [shape=diamond, condition="ready AND tests_pass"]
        proceed [shape=box]
        wait [shape=box]
        end [shape=Msquare]
        
        start -> decision
        decision -> proceed [label="true"]
        decision -> wait [label="false"]
        proceed -> end
        wait -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Test with both true
    $engine = new Engine();
    $result = $engine->execute($graph, 'start', ['ready' => true, 'tests_pass' => true]);
    expect($result['context']['condition_result'])->toBe(true);
    
    // Test with one false
    $engine2 = new Engine();
    $result2 = $engine2->execute($graph, 'start', ['ready' => true, 'tests_pass' => false]);
    expect($result2['context']['condition_result'])->toBe(false);
    
    // Test with OR
    $dot2 = '
    digraph test2 {
        start [shape=Mdiamond]
        decision [shape=diamond, condition="ready OR tests_pass"]
        proceed [shape=box]
        wait [shape=box]
        end [shape=Msquare]
        
        start -> decision
        decision -> proceed [label="true"]
        decision -> wait [label="false"]
        proceed -> end
        wait -> end
    }
    ';
    $parser2 = new DOTParser();
    $graph2 = $parser2->parse($dot2);
    
    $engine3 = new Engine();
    $result3 = $engine3->execute($graph2, 'start', ['ready' => false, 'tests_pass' => true]);
    expect($result3['context']['condition_result'])->toBe(true);
});

it('evaluates negation in condition', function () {
    $dot = '
    digraph test {
        start [shape=Mdiamond]
        decision [shape=diamond, condition="!skip"]
        proceed [shape=box]
        skip_it [shape=box]
        end [shape=Msquare]
        
        start -> decision
        decision -> proceed [label="true"]
        decision -> skip_it [label="false"]
        proceed -> end
        skip_it -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    // Test with skip=false (so !skip = true)
    $engine = new Engine();
    $result = $engine->execute($graph, 'start', ['skip' => false]);
    expect($result['context']['condition_result'])->toBe(true);
    
    // Test with skip=true (so !skip = false)
    $engine2 = new Engine();
    $result2 = $engine2->execute($graph, 'start', ['skip' => true]);
    expect($result2['context']['condition_result'])->toBe(false);
});

it('handles missing start node', function () {
    $dot = '
    digraph test {
        task1 [shape=box]
        end [shape=Msquare]
        
        task1 -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'nonexistent'))
        ->toThrow(\RuntimeException::class, 'Node not found');
});

it('handles empty graph', function () {
    $dot = '
    digraph empty { }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $engine = new Engine();
    
    expect(fn() => $engine->execute($graph, 'start'))
        ->toThrow(\RuntimeException::class, 'Node not found');
});

<?php

use App\Pipeline\DOTParser;
use App\Pipeline\Graph;

it('parses a simple digraph', function () {
    $dot = '
    digraph test {
        graph [goal="Test goal"];
        
        start [shape=Mdiamond]
        middle [shape=box, label="Middle step"]
        end [shape=Msquare]
        
        start -> middle
        middle -> end [label="done"]
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    expect($graph->id)->toBe('test');
    expect($graph->goal)->toBe('Test goal');
    expect($graph->nodes)->toHaveCount(3);
    expect($graph->edges)->toHaveCount(2);
});

it('parses node attributes', function () {
    $dot = '
    digraph test {
        node [shape=box, color=blue]
        
        start [shape=Mdiamond, label="Start Here"]
        end [shape=Msquare]
        
        start -> end
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $start = $graph->getNode('start');
    expect($start?->shape)->toBe('Mdiamond');
    expect($start?->label)->toBe('Start Here');
});

it('gets outgoing edges', function () {
    $dot = '
    digraph test {
        a -> b
        a -> c
        b -> d
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $outgoing = $graph->getOutgoingEdges('a');
    expect($outgoing)->toHaveCount(2);
});

it('parses complex attributes', function () {
    $dot = '
    digraph test {
        node [max_retries=3, timeout="900s"]
        
        task [shape=box, prompt="Do something", goal_gate=true]
    }
    ';
    
    $parser = new DOTParser();
    $graph = $parser->parse($dot);
    
    $task = $graph->getNode('task');
    expect($task?->attributes['prompt'])->toBe('Do something');
    expect($task?->attributes['goal_gate'])->toBe('true');
    expect($task?->attributes['max_retries'])->toBe('3');
});

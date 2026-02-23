<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Pipeline\DOTParser;

class VisualizeGraphCommand extends Command
{
    protected $signature = 'factory:visualize {file : The DOT file to visualize}
                            {--format=text : Output format (text, json, dot)}';

    protected $description = 'Visualize a DOT graph pipeline';

    public function handle(): int
    {
        $file = $this->argument('file');
        $format = $this->option('format');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $dot = file_get_contents($file);
        $parser = new DOTParser();
        $graph = $parser->parse($dot);

        match($format) {
            'json' => $this->outputJson($graph),
            'dot' => $this->outputDot($dot),
            default => $this->outputText($graph),
        };

        return 0;
    }

    protected function outputText($graph): void
    {
        $this->info("Graph: {$graph->id}");
        $this->info("Goal: {$graph->goal}");
        $this->line('');

        $this->info('Nodes:');
        foreach ($graph->nodes as $node) {
            $status = match($node->shape) {
                'Mdiamond' => '🚀 START',
                'Msquare' => '🏁 END',
                'hexagon' => '⏸️ APPROVAL',
                'diamond' => '🔀 CONDITION',
                'component' => '⚡ PARALLEL',
                'tripleoctagon' => '📥 FAN-IN',
                'box' => '📝 TASK',
                default => '❓ ' . $node->shape,
            };
            
            $this->line("  {$status} {$node->id}");
            if ($node->label && $node->label !== $node->id) {
                $this->line("       Label: {$node->label}");
            }
        }

        $this->line('');
        $this->info('Edges:');
        foreach ($graph->edges as $edge) {
            $label = $edge->label ? " [{$edge->label}]" : '';
            $this->line("  {$edge->from} → {$edge->to}{$label}");
        }
    }

    protected function outputJson($graph): void
    {
        $data = [
            'id' => $graph->id,
            'goal' => $graph->goal,
            'label' => $graph->label,
            'nodes' => array_map(fn($n) => [
                'id' => $n->id,
                'label' => $n->label,
                'shape' => $n->shape,
                'attributes' => $n->attributes,
            ], $graph->nodes),
            'edges' => array_map(fn($e) => [
                'from' => $e->from,
                'to' => $e->to,
                'label' => $e->label,
            ], $graph->edges),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function outputDot($dot): void
    {
        $this->line($dot);
    }
}

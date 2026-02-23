<?php

namespace App\Pipeline;

class Graph
{
    public string $id = '';
    public string $goal = '';
    public string $label = '';
    public array $graphAttributes = [];
    public array $nodeDefaults = [];
    public array $edgeDefaults = [];
    public array $nodes = [];
    public array $edges = [];
    public array $subgraphs = [];

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        $this->edges[] = $edge;
    }

    public function getNode(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    public function getOutgoingEdges(string $nodeId): array
    {
        return array_filter($this->edges, fn($e) => $e->from === $nodeId);
    }

    /**
     * Get outgoing edges filtered by label.
     */
    public function getOutgoingEdgesByLabel(string $nodeId, string $label): array
    {
        return array_filter(
            $this->edges,
            fn($e) => $e->from === $nodeId && strtolower($e->label) === strtolower($label)
        );
    }
}

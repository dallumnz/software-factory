<?php

namespace App\Pipeline;

class DOTParser
{
    /**
     * Parse a DOT string into a Graph object.
     * Supports a strict subset of Graphviz DOT language.
     */
    public function parse(string $dot): Graph
    {
        $graph = new Graph();
        
        // Remove comments
        $dot = $this->stripComments($dot);
        
        // Find graph declaration
        if (preg_match('/^\s*digraph\s+([A-Za-z_][A-Za-z0-9_]*)\s*\{/', $dot, $matches)) {
            $graph->id = $matches[1];
            $dot = substr($dot, strlen($matches[0]));
        }
        
        // Find closing brace
        $dot = trim($dot, '}');
        
        // Parse statements
        $this->parseStatements($dot, $graph);
        
        return $graph;
    }

    /**
     * Parse a DOT file.
     */
    public function parseFile(string $path): Graph
    {
        return $this->parse(file_get_contents($path));
    }

    private function stripComments(string $dot): string
    {
        // Remove single-line comments
        $dot = preg_replace('/\/\/[^\n]*/', '', $dot);
        // Remove multi-line comments
        $dot = preg_replace('/\/\*.*?\*\//s', '', $dot);
        return $dot;
    }

    private function parseStatements(string $dot, Graph $graph): void
    {
        // First: parse graph attributes: graph [key=value, ...];
        if (preg_match_all('/\bgraph\s*\[\s*([^\]]+)\s*\]/', $dot, $matches)) {
            foreach ($matches[1] as $attrBlock) {
                $graph->graphAttributes = array_merge(
                    $graph->graphAttributes,
                    $this->parseAttributes($attrBlock)
                );
            }
        }
        
        $graph->goal = $graph->graphAttributes['goal'] ?? '';
        $graph->label = $graph->graphAttributes['label'] ?? '';
        
        // Parse node defaults: node [key=value, ...];
        if (preg_match_all('/\bnode\s*\[\s*([^\]]+)\s*\]/', $dot, $matches)) {
            foreach ($matches[1] as $attrBlock) {
                $graph->nodeDefaults = $this->parseAttributes($attrBlock);
            }
        }
        
        // Parse edge defaults: edge [key=value, ...];
        if (preg_match_all('/\bedge\s*\[\s*([^\]]+)\s*\]/', $dot, $matches)) {
            foreach ($matches[1] as $attrBlock) {
                $graph->edgeDefaults = array_merge(
                    $graph->edgeDefaults,
                    $this->parseAttributes($attrBlock)
                );
            }
        }
        
        // Parse edges with attributes: A -> B [key=value];
        // This must be done BEFORE parsing standalone nodes
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*([^\]]+)\s*\]/', $dot, $matches)) {
            foreach ($matches[1] as $index => $from) {
                $to = $matches[2][$index];
                $attrs = $this->parseAttributes($matches[3][$index]);
                $attrs = array_merge($graph->edgeDefaults, $attrs);
                
                $edge = new Edge(
                    from: $from,
                    to: $to,
                    label: $attrs['label'] ?? '',
                    condition: $attrs['condition'] ?? '',
                    weight: (int)($attrs['weight'] ?? 0)
                );
                $graph->addEdge($edge);
            }
        }
        
        // Parse edges without attributes: A -> B;
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)/', $dot, $matches)) {
            foreach ($matches[1] as $index => $from) {
                $to = $matches[2][$index];
                
                // Check if edge already exists (from edges with attributes)
                $exists = false;
                foreach ($graph->edges as $existing) {
                    if ($existing->from === $from && $existing->to === $to) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $edge = new Edge(from: $from, to: $to);
                    $graph->addEdge($edge);
                }
            }
        }
        
        // Parse nodes with attributes: NodeId [key=value];
        // Must NOT be an edge (not followed by ->)
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*([^\]]+)\s*\]/s', $dot, $matches)) {
            foreach ($matches[1] as $index => $id) {
                // Skip if this is a graph/node/edge keyword
                if (in_array($id, ['graph', 'node', 'edge', 'digraph', 'subgraph', 'strict'])) {
                    continue;
                }
                
                // Get the full match and surrounding context
                $fullMatch = $matches[0][$index];
                $attrsStr = $matches[2][$index];
                
                // Find position in original DOT to check if it's part of an edge
                $pos = strpos($dot, $fullMatch);
                if ($pos !== false) {
                    // Get surrounding text (50 chars before)
                    $start = max(0, $pos - 50);
                    $context = substr($dot, $start, $pos - $start);
                    
                    // Skip if this looks like an edge attribute block (preceded by ->)
                    if (str_contains($context, '->')) {
                        continue;
                    }
                }
                
                $attrs = $this->parseAttributes($attrsStr);
                $attrs = array_merge($graph->nodeDefaults, $attrs);
                
                $node = new Node(
                    id: $id,
                    label: $attrs['label'] ?? $id,
                    shape: $attrs['shape'] ?? 'box',
                    attributes: $attrs
                );
                $graph->addNode($node);
            }
        }
        
        // Parse standalone nodes (no attributes, but end with semicolon)
        // Exclude graph, node, edge, digraph keywords
        $keywords = ['graph', 'node', 'edge', 'digraph', 'subgraph', 'strict'];
        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*;/', $dot, $matches)) {
            foreach ($matches[1] as $id) {
                if (in_array($id, $keywords)) {
                    continue;
                }
                if (!isset($graph->nodes[$id])) {
                    $node = new Node(
                        id: $id,
                        label: $id,
                        shape: 'box',
                        attributes: $graph->nodeDefaults
                    );
                    $graph->addNode($node);
                }
            }
        }
    }

    private function parseAttributes(string $block): array
    {
        $attrs = [];
        
        // Split by comma, then parse each key=value
        $parts = array_map('trim', explode(',', $block));
        
        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = array_map('trim', explode('=', $part, 2));
                // Remove quotes from string values
                $value = trim($value, '"');
                $attrs[$key] = $value;
            }
        }
        
        return $attrs;
    }
}

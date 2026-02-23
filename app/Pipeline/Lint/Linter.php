<?php

namespace App\Pipeline\Lint;

use App\Pipeline\Graph;
use App\Pipeline\Node;

class Linter
{
    protected array $errors = [];
    protected array $warnings = [];
    
    /**
     * Lint a graph and return issues.
     */
    public function lint(Graph $graph): array
    {
        $this->errors = [];
        $this->warnings = [];
        
        $this->lintStructure($graph);
        $this->lintNodes($graph);
        $this->lintEdges($graph);
        
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'passed' => empty($this->errors),
        ];
    }
    
    /**
     * Check graph-level structure.
     */
    protected function lintStructure(Graph $graph): void
    {
        // Check for graph ID
        if (empty($graph->id)) {
            $this->warnings[] = 'Graph has no ID';
        }
        
        // Check for goal
        if (empty($graph->goal)) {
            $this->warnings[] = 'Graph has no goal defined';
        }
        
        // Check for nodes
        if (empty($graph->nodes)) {
            $this->errors[] = 'Graph has no nodes';
        }
        
        // Check for edges
        if (empty($graph->edges)) {
            $this->warnings[] = 'Graph has no edges';
        }
    }
    
    /**
     * Check node requirements.
     */
    protected function lintNodes(Graph $graph): void
    {
        $startNodes = [];
        $endNodes = [];
        $nodeIds = [];
        
        foreach ($graph->nodes as $node) {
            $nodeId = $node->id;
            
            // Check for duplicate IDs
            if (isset($nodeIds[$nodeId])) {
                $this->errors[] = "Duplicate node ID: {$nodeId}";
            }
            $nodeIds[$nodeId] = true;
            
            // Check start nodes
            if ($node->shape === 'Mdiamond') {
                $startNodes[] = $nodeId;
            }
            
            // Check end nodes
            if ($node->shape === 'Msquare') {
                $endNodes[] = $nodeId;
            }
            
            // Check box nodes have prompts
            if ($node->shape === 'box' && empty($node->attributes['prompt']) && empty($node->label)) {
                $this->warnings[] = "Node '{$nodeId}' (box) has no prompt";
            }
            
            // Check diamond nodes have conditions
            if ($node->shape === 'diamond' && empty($node->attributes['condition'])) {
                $this->warnings[] = "Node '{$nodeId}' (diamond) has no condition";
            }
            
            // Check hexagon nodes for approval routing
            if ($node->shape === 'hexagon') {
                $edges = $graph->getOutgoingEdges($nodeId);
                $hasApprovalEdge = false;
                $hasRejectionEdge = false;
                
                foreach ($edges as $edge) {
                    if (strtolower($edge->label) === 'approved') $hasApprovalEdge = true;
                    if (strtolower($edge->label) === 'rejected') $hasRejectionEdge = true;
                }
                
                if (!$hasApprovalEdge || !$hasRejectionEdge) {
                    $this->warnings[] = "Node '{$nodeId}' (hexagon) should have 'approved' and 'rejected' edge labels";
                }
            }
        }
        
        // Check for exactly one start
        if (count($startNodes) === 0) {
            $this->errors[] = 'Graph must have exactly one start node (Mdiamond)';
        } elseif (count($startNodes) > 1) {
            $this->errors[] = 'Graph must have exactly one start node, found: ' . implode(', ', $startNodes);
        }
        
        // Check for exactly one end
        if (count($endNodes) === 0) {
            $this->errors[] = 'Graph must have exactly one end node (Msquare)';
        } elseif (count($endNodes) > 1) {
            $this->warnings[] = 'Graph has multiple exit nodes: ' . implode(', ', $endNodes);
        }
    }
    
    /**
     * Check edge connectivity.
     */
    protected function lintEdges(Graph $graph): void
    {
        $nodeIds = array_keys($graph->nodes);
        
        foreach ($graph->edges as $edge) {
            // Check source node exists
            if (!in_array($edge->from, $nodeIds)) {
                $this->errors[] = "Edge from non-existent node: {$edge->from}";
            }
            
            // Check target node exists
            if (!in_array($edge->to, $nodeIds)) {
                $this->errors[] = "Edge to non-existent node: {$edge->to}";
            }
            
            // Check for orphaned edges from start
            if ($graph->getNode($edge->from)?->shape === 'Mdiamond' && count($graph->getOutgoingEdges($edge->from)) > 1) {
                $this->warnings[] = "Start node has multiple outgoing edges";
            }
        }
        
        // Check for unreachable nodes
        $reachable = $this->findReachableNodes($graph);
        foreach ($nodeIds as $nodeId) {
            if (!in_array($nodeId, $reachable)) {
                $this->warnings[] = "Unreachable node: {$nodeId}";
            }
        }
    }
    
    /**
     * Find all reachable nodes from start.
     */
    protected function findReachableNodes(Graph $graph): array
    {
        $startNode = null;
        foreach ($graph->nodes as $node) {
            if ($node->shape === 'Mdiamond') {
                $startNode = $node->id;
                break;
            }
        }
        
        if (!$startNode) return [];
        
        $visited = [];
        $queue = [$startNode];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            
            if (in_array($current, $visited)) continue;
            $visited[] = $current;
            
            foreach ($graph->getOutgoingEdges($current) as $edge) {
                if (!in_array($edge->to, $visited)) {
                    $queue[] = $edge->to;
                }
            }
        }
        
        return $visited;
    }
}

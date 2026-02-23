<?php

namespace App\Pipeline;

class Edge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $label = '',
        public readonly string $condition = '',
        public readonly int $weight = 0,
    ) {}
}

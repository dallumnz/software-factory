<?php

namespace App\Pipeline;

class Node
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $shape,
        public readonly array $attributes = [],
    ) {}
}

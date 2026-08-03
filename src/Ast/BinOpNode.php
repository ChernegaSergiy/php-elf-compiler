<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A binary operation, e.g. `left op right`.
 */
class BinOpNode implements AstNode
{
    public function __construct(
        public AstNode $left,
        public string $op,
        public AstNode $right,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A `~expr` bitwise NOT.
 */
class BitNotNode implements AstNode
{
    public function __construct(
        public AstNode $expr,
    ) {
    }
}

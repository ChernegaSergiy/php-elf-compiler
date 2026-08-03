<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A `!expr` logical negation.
 */
class NotNode implements AstNode
{
    public function __construct(
        public AstNode $expr,
    ) {
    }
}

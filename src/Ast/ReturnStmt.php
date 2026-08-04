<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A `return expr;` statement.
 */
class ReturnStmt implements AstNode
{
    public function __construct(
        public ?AstNode $expr = null,
    ) {
    }
}

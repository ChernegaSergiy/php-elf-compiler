<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * Reassignment of an existing variable: `name = expr;`.
 */
class AssignStmt implements AstNode
{
    public function __construct(
        public string $name,
        public AstNode $expr,
    ) {
    }
}

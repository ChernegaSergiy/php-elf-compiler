<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A variable declaration: `let name = expr;`.
 */
class LetStmt implements AstNode
{
    public function __construct(
        public string $name,
        public AstNode $expr,
    ) {
    }
}

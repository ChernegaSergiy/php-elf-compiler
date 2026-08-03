<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * A `while condition { body }` loop.
 */
class WhileStmt implements AstNode
{
    /**
     * @param AstNode[] $body
     */
    public function __construct(
        public AstNode $condition,
        public array $body,
    ) {
    }
}

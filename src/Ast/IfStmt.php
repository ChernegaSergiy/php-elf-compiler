<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ast;

/**
 * An `if (condition) { then } else { else }` statement.
 */
class IfStmt implements AstNode
{
    /**
     * @param AstNode[] $thenBody
     * @param AstNode[]|null $elseBody
     */
    public function __construct(
        public AstNode $condition,
        public array $thenBody,
        public ?array $elseBody = null,
    ) {
    }
}

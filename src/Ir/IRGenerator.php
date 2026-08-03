<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Ir;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\IfStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use Exception;

/**
 * Converts AST to architecture-agnostic three-address style IR.
 */
class IRGenerator
{
    private int $tempCounter = 0;

    private int $labelCounter = 0;

    /**
     * @param AstNode[] $ast
     */
    public function generate(array $ast): Program
    {
        $program = new Program();

        foreach ($ast as $stmt) {
            $this->compileStmt($stmt, $program);
        }

        return $program;
    }

    private function compileStmt(AstNode $node, Program $program): void
    {
        if ($node instanceof LetStmt || $node instanceof AssignStmt) {
            $valueTemp = $this->compileExpr($node->expr, $program);
            $program->add(new Instruction('store_var', null, [$node->name, $valueTemp]));

            return;
        }

        if ($node instanceof PrintStmt) {
            if ($node->expr instanceof StringNode) {
                $program->add(new Instruction('print_str', null, [$node->expr->val]));

                return;
            }

            $valueTemp = $this->compileExpr($node->expr, $program);
            $program->add(new Instruction('print_num', null, [$valueTemp]));

            return;
        }

        if ($node instanceof WhileStmt) {
            $startLabel = $this->nextLabel('while_start');
            $endLabel = $this->nextLabel('while_end');

            $program->add(new Instruction('label', null, [$startLabel]));
            $conditionTemp = $this->compileExpr($node->condition, $program);
            $program->add(new Instruction('jump_if_zero', null, [$conditionTemp, $endLabel]));

            foreach ($node->body as $stmt) {
                $this->compileStmt($stmt, $program);
            }

            $program->add(new Instruction('jump', null, [$startLabel]));
            $program->add(new Instruction('label', null, [$endLabel]));

            return;
        }

        if ($node instanceof IfStmt) {
            $elseLabel = $this->nextLabel('if_else');
            $endLabel = $this->nextLabel('if_end');

            $conditionTemp = $this->compileExpr($node->condition, $program);
            $program->add(new Instruction('jump_if_zero', null, [$conditionTemp, $elseLabel]));

            foreach ($node->thenBody as $stmt) {
                $this->compileStmt($stmt, $program);
            }

            $program->add(new Instruction('jump', null, [$endLabel]));
            $program->add(new Instruction('label', null, [$elseLabel]));

            if ($node->elseBody !== null) {
                foreach ($node->elseBody as $stmt) {
                    $this->compileStmt($stmt, $program);
                }
            }

            $program->add(new Instruction('label', null, [$endLabel]));

            return;
        }

        throw new Exception('Unsupported statement node: ' . $node::class);
    }

    private function compileExpr(AstNode $node, Program $program): string
    {
        if ($node instanceof NumberNode) {
            $temp = $this->nextTemp();
            $program->add(new Instruction('const_int', $temp, [$node->val]));

            return $temp;
        }

        if ($node instanceof VarNode) {
            $temp = $this->nextTemp();
            $program->add(new Instruction('load_var', $temp, [$node->name]));

            return $temp;
        }

        if ($node instanceof BinOpNode) {
            $left = $this->compileExpr($node->left, $program);
            $right = $this->compileExpr($node->right, $program);

            $opcode = match ($node->op) {
                '+' => 'add_int',
                '<' => 'cmp_lt',
                '==' => 'cmp_eq',
                '!=' => 'cmp_ne',
                default => throw new Exception("Unsupported binary operator: {$node->op}"),
            };

            $temp = $this->nextTemp();
            $program->add(new Instruction($opcode, $temp, [$left, $right]));

            return $temp;
        }

        if ($node instanceof NotNode) {
            $valueTemp = $this->compileExpr($node->expr, $program);
            $temp = $this->nextTemp();
            $program->add(new Instruction('not_bool', $temp, [$valueTemp]));

            return $temp;
        }

        throw new Exception('Unsupported expression node: ' . $node::class);
    }

    private function nextTemp(): string
    {
        $this->tempCounter++;

        return '%' . $this->tempCounter;
    }

    private function nextLabel(string $prefix): string
    {
        $this->labelCounter++;

        return sprintf('%s_%d', $prefix, $this->labelCounter);
    }
}

<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Midend;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BitNotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\CallNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\FunctionDecl;
use ChernegaSergiy\TrypilliaCompiler\Ast\IfStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\ReturnStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use Exception;

/**
 * Converts AST to architecture-agnostic three-address style IR.
 */
class IrGenerator
{
    private int $tempCounter = 0;

    private int $labelCounter = 0;

    /** Default width for unannotated integer literals (RFC-0001 §3.1). */
    private const DEFAULT_WIDTH = Width::I64;

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
        if ($node instanceof FunctionDecl) {
            $program->beginFunction($node->name);

            foreach ($node->params as $param) {
                $program->add(new Instruction('param', null, [
                    Operand::temp(self::DEFAULT_WIDTH, $param['name']),
                ]));
            }

            foreach ($node->body as $stmt) {
                $this->compileStmt($stmt, $program);
            }

            if (!$this->lastInstructionIsRet($program)) {
                $program->add(new Instruction('ret', null, []));
            }

            $program->endFunction();

            return;
        }

        if ($node instanceof ReturnStmt) {
            if ($node->expr !== null) {
                $valueTemp = $this->compileExpr($node->expr, $program);
                $program->add(new Instruction('ret', null, [
                    Operand::temp(self::DEFAULT_WIDTH, $valueTemp),
                ]));
            } else {
                $program->add(new Instruction('ret', null, []));
            }

            return;
        }

        if ($node instanceof LetStmt || $node instanceof AssignStmt) {
            $valueTemp = $this->compileExpr($node->expr, $program);
            $program->add(new Instruction('store_var', null, [
                Operand::temp(self::DEFAULT_WIDTH, $node->name),
                Operand::temp(self::DEFAULT_WIDTH, $valueTemp),
            ]));

            return;
        }

        if ($node instanceof PrintStmt) {
            if ($node->expr instanceof StringNode) {
                $program->add(new Instruction('print_str', null, [
                    Operand::temp(self::DEFAULT_WIDTH, $node->expr->val),
                ]));

                return;
            }

            $valueTemp = $this->compileExpr($node->expr, $program);
            $program->add(new Instruction('print_num', null, [
                Operand::temp(self::DEFAULT_WIDTH, $valueTemp),
            ]));

            return;
        }

        if ($node instanceof WhileStmt) {
            $startLabel = $this->nextLabel('while_start');
            $endLabel = $this->nextLabel('while_end');

            $program->add(new Instruction('label', null, [
                Operand::label($startLabel),
            ]));
            $conditionTemp = $this->compileExpr($node->condition, $program);
            $program->add(new Instruction('jump_if_zero', null, [
                Operand::temp(self::DEFAULT_WIDTH, $conditionTemp),
                Operand::label($endLabel),
            ]));

            foreach ($node->body as $stmt) {
                $this->compileStmt($stmt, $program);
            }

            $program->add(new Instruction('jump', null, [
                Operand::label($startLabel),
            ]));
            $program->add(new Instruction('label', null, [
                Operand::label($endLabel),
            ]));

            return;
        }

        if ($node instanceof IfStmt) {
            $elseLabel = $this->nextLabel('if_else');
            $endLabel = $this->nextLabel('if_end');

            $conditionTemp = $this->compileExpr($node->condition, $program);
            $program->add(new Instruction('jump_if_zero', null, [
                Operand::temp(self::DEFAULT_WIDTH, $conditionTemp),
                Operand::label($elseLabel),
            ]));

            foreach ($node->thenBody as $stmt) {
                $this->compileStmt($stmt, $program);
            }

            $program->add(new Instruction('jump', null, [
                Operand::label($endLabel),
            ]));
            $program->add(new Instruction('label', null, [
                Operand::label($elseLabel),
            ]));

            if ($node->elseBody !== null) {
                foreach ($node->elseBody as $stmt) {
                    $this->compileStmt($stmt, $program);
                }
            }

            $program->add(new Instruction('label', null, [
                Operand::label($endLabel),
            ]));

            return;
        }

        throw new Exception('Unsupported statement node: ' . $node::class);
    }

    private function compileExpr(AstNode $node, Program $program): string
    {
        if ($node instanceof NumberNode) {
            $temp = $this->nextTemp();
            $program->add(new Instruction('const_int', $temp, [
                Operand::constInt(self::DEFAULT_WIDTH, $node->val),
            ]));

            return $temp;
        }

        if ($node instanceof VarNode) {
            $temp = $this->nextTemp();
            $program->add(new Instruction('load_var', $temp, [
                Operand::temp(self::DEFAULT_WIDTH, $node->name),
            ]));

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
                '&' => 'bit_and',
                '|' => 'bit_or',
                '^' => 'bit_xor',
                '<<' => 'shl',
                '>>' => 'shr',
                '>>>' => 'shr_u',
                default => throw new Exception("Unsupported binary operator: {$node->op}"),
            };

            $temp = $this->nextTemp();
            $program->add(new Instruction($opcode, $temp, [
                Operand::temp(self::DEFAULT_WIDTH, $left),
                Operand::temp(self::DEFAULT_WIDTH, $right),
            ]));

            return $temp;
        }

        if ($node instanceof NotNode) {
            $valueTemp = $this->compileExpr($node->expr, $program);
            $temp = $this->nextTemp();
            $program->add(new Instruction('not_bool', $temp, [
                Operand::temp(self::DEFAULT_WIDTH, $valueTemp),
            ]));

            return $temp;
        }

        if ($node instanceof BitNotNode) {
            $valueTemp = $this->compileExpr($node->expr, $program);
            $temp = $this->nextTemp();
            $program->add(new Instruction('bit_not', $temp, [
                Operand::temp(self::DEFAULT_WIDTH, $valueTemp),
            ]));

            return $temp;
        }

        if ($node instanceof CallNode) {
            foreach ($node->args as $arg) {
                $argTemp = $this->compileExpr($arg, $program);
                $program->add(new Instruction('arg', null, [
                    Operand::temp(self::DEFAULT_WIDTH, $argTemp),
                ]));
            }

            $temp = $this->nextTemp();
            $program->add(new Instruction('call', $temp, [
                Operand::temp(self::DEFAULT_WIDTH, $node->name),
            ]));

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

    private function lastInstructionIsRet(Program $program): bool
    {
        $instructions = $program->isInsideFunction()
            ? $program->functions[array_key_last($program->functions)]
            : $program->instructions;

        return $instructions !== [] && end($instructions)->opcode === 'ret';
    }
}

<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use ChernegaSergiy\TrypilliaCompiler\CodeGen\X86Emitter;

/**
 * Walks a Trypillia AST and compiles it into a native x86_64 ELF binary.
 */
class Compiler
{
    /**
     * @param AstNode[] $ast
     */
    public static function compile(array $ast, string $filename): void
    {
        $emitter = new X86Emitter();
        foreach ($ast as $stmt) {
            self::compileStmt($stmt, $emitter);
        }
        $emitter->generateBinary($filename);
    }

    private static function compileStmt(AstNode $node, X86Emitter $emitter): void
    {
        if ($node instanceof LetStmt || $node instanceof AssignStmt) {
            self::compileExpr($node->expr, $emitter);
            $emitter->storeLocal($node->name);
        } elseif ($node instanceof PrintStmt) {
            if ($node->expr instanceof StringNode) {
                $emitter->emitPrintString($node->expr->val);
            } else {
                self::compileExpr($node->expr, $emitter);
                $emitter->emitPrintNumberInRax();
            }
        } elseif ($node instanceof WhileStmt) {
            $loopStart = $emitter->getCurrentOffset();
            self::compileExpr($node->condition, $emitter);
            $emitter->emitCmpRaxImm0();
            $patchOffset = $emitter->emitJe_ForwardPlaceholder();

            foreach ($node->body as $stmt) {
                self::compileStmt($stmt, $emitter);
            }

            $emitter->emitJmp_Backward($loopStart);
            $emitter->patchForwardJump($patchOffset);
        }
    }

    private static function compileExpr(AstNode $node, X86Emitter $emitter): void
    {
        if ($node instanceof NumberNode) {
            $emitter->movRaxImm($node->val);
        } elseif ($node instanceof VarNode) {
            $emitter->loadLocal($node->name);
        } elseif ($node instanceof BinOpNode) {
            self::compileExpr($node->right, $emitter);
            $emitter->pushRax();
            self::compileExpr($node->left, $emitter);
            $emitter->popRdx();
            if ($node->op === '+') {
                $emitter->addRaxRdx();
            } elseif ($node->op === '<') {
                $emitter->emitCmpRaxRdx();
                $emitter->emitSetlRax();
            }
        }
    }
}

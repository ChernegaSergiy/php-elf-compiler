<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Parser;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BitNotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\IfStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NotNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Token;
use ChernegaSergiy\TrypilliaCompiler\Lexer\TokenType;
use Exception;

/**
 * Recursive-descent parser that turns a token stream into an AST.
 *
 * Expression parsing uses precedence climbing (RFC-0001 §3.4):
 *   ~ tightest
 *   << >> >>>
 *   &
 *   ^
 *   |
 *   + -
 *   < >
 *   == !=  loosest
 */
class Parser
{
    private int $pos = 0;

    /**
     * @param Token[] $tokens
     */
    public function __construct(private array $tokens)
    {
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function consume(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(TokenType $type): Token
    {
        $tok = $this->consume();
        if ($tok->type !== $type) {
            throw new Exception("Expected token {$type->name}, got {$tok->type->name}");
        }

        return $tok;
    }

    /**
     * @return AstNode[]
     */
    public function parse(): array
    {
        $stmts = [];
        while ($this->peek()->type !== TokenType::EOF) {
            $stmts[] = $this->parseStmt();
        }

        return $stmts;
    }

    private function parseStmt(): AstNode
    {
        $tok = $this->peek();

        if ($tok->type === TokenType::LET) {
            $this->consume();
            $name = $this->expect(TokenType::IDENTIFIER)->value;
            $this->expect(TokenType::ASSIGN);
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);

            return new LetStmt($name, $expr);
        } elseif ($tok->type === TokenType::PRINT) {
            $this->consume();
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);

            return new PrintStmt($expr);
        } elseif ($tok->type === TokenType::WHILE) {
            $this->consume();
            $condition = $this->parseExpr();
            $this->expect(TokenType::LBRACE);
            $body = [];
            while ($this->peek()->type !== TokenType::RBRACE) {
                $body[] = $this->parseStmt();
            }
            $this->consume(); // }

            return new WhileStmt($condition, $body);
        } elseif ($tok->type === TokenType::IF) {
            $this->consume();
            $condition = $this->parseExpr();
            $this->expect(TokenType::LBRACE);
            $thenBody = [];
            while ($this->peek()->type !== TokenType::RBRACE) {
                $thenBody[] = $this->parseStmt();
            }
            $this->consume(); // }

            $elseBody = null;
            if ($this->peek()->type === TokenType::ELSE) {
                $this->consume();
                $this->expect(TokenType::LBRACE);
                $elseBody = [];
                while ($this->peek()->type !== TokenType::RBRACE) {
                    $elseBody[] = $this->parseStmt();
                }
                $this->consume(); // }
            }

            return new IfStmt($condition, $thenBody, $elseBody);
        } elseif ($tok->type === TokenType::IDENTIFIER) {
            $name = $this->consume()->value;
            $this->expect(TokenType::ASSIGN);
            $expr = $this->parseExpr();
            $this->expect(TokenType::SEMICOLON);

            return new AssignStmt($name, $expr);
        } else {
            throw new Exception('Unexpected token: ' . $tok->value);
        }
    }

    private function parseExpr(): AstNode
    {
        return $this->parseBinaryExpr(0);
    }

    /**
     * Precedence climbing: parse a binary expression at the given minimum precedence.
     */
    private function parseBinaryExpr(int $minPrecedence): AstNode
    {
        $left = $this->parseUnary();

        while ($this->isBinaryOp() && $this->precedence($this->peek()->type) >= $minPrecedence) {
            $opTok = $this->consume();
            $prec = $this->precedence($opTok->type);
            $right = $this->parseBinaryExpr($prec + 1);
            $left = new BinOpNode($left, $opTok->value, $right);
        }

        return $left;
    }

    private function parseUnary(): AstNode
    {
        $tok = $this->peek();

        if ($tok->type === TokenType::NOT) {
            $this->consume();
            $expr = $this->parseUnary();

            return new NotNode($expr);
        }

        if ($tok->type === TokenType::TILDE) {
            $this->consume();
            $expr = $this->parseUnary();

            return new BitNotNode($expr);
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): AstNode
    {
        $tok = $this->consume();

        return match ($tok->type) {
            TokenType::NUMBER => new NumberNode((int) $tok->value),
            TokenType::STRING => new StringNode($tok->value),
            TokenType::IDENTIFIER => new VarNode($tok->value),
            default => throw new Exception('Parse error: ' . $tok->value),
        };
    }

    private function isBinaryOp(): bool
    {
        return in_array($this->peek()->type, [
            TokenType::PLUS,
            TokenType::MINUS,
            TokenType::MULT,
            TokenType::LESS,
            TokenType::GREATER,
            TokenType::EQUALS,
            TokenType::NOTEQUALS,
            TokenType::AMP,
            TokenType::BITOR,
            TokenType::CARET,
            TokenType::SHL,
            TokenType::SHR,
            TokenType::SHRU,
        ], true);
    }

    /**
     * Returns the precedence level of a binary operator token (higher = tighter binding).
     */
    private function precedence(TokenType $type): int
    {
        return match ($type) {
            TokenType::BITOR => 1,
            TokenType::CARET => 2,
            TokenType::AMP => 3,
            TokenType::SHL, TokenType::SHR, TokenType::SHRU => 4,
            TokenType::PLUS, TokenType::MINUS => 5,
            TokenType::LESS, TokenType::GREATER => 6,
            TokenType::EQUALS, TokenType::NOTEQUALS => 7,
            default => 0,
        };
    }
}

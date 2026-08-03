<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Parser;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\AstNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\IfStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
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
            throw new Exception("Очікувався токен {$type->name}, отримано {$tok->type->name}");
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
            throw new Exception('Неочікуваний токен: ' . $tok->value);
        }
    }

    private function parseExpr(): AstNode
    {
        $tok = $this->consume();
        $node = match ($tok->type) {
            TokenType::NUMBER => new NumberNode((int) $tok->value),
            TokenType::STRING => new StringNode($tok->value),
            TokenType::IDENTIFIER => new VarNode($tok->value),
            default => throw new Exception('Помилка виразу: ' . $tok->value),
        };

        if (in_array($this->peek()->type, [TokenType::PLUS, TokenType::MINUS, TokenType::MULT, TokenType::LESS, TokenType::GREATER, TokenType::EQUALS], true)) {
            $opTok = $this->consume();
            $right = $this->parseExpr();
            $node = new BinOpNode($node, $opTok->value, $right);
        }

        return $node;
    }
}

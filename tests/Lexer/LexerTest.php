<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Lexer;

use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Lexer\TokenType;
use PHPUnit\Framework\TestCase;

class LexerTest extends TestCase
{
    public function testTokenizesLetStatement(): void
    {
        $tokens = Lexer::run('let a = 15;');

        $this->assertSame(
            [TokenType::LET, TokenType::IDENTIFIER, TokenType::ASSIGN, TokenType::NUMBER, TokenType::SEMICOLON, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
        $this->assertSame('a', $tokens[1]->value);
        $this->assertSame('15', $tokens[3]->value);
    }

    public function testTokenizesPrintWithStringLiteral(): void
    {
        $tokens = Lexer::run('print "hello";');

        $this->assertSame(TokenType::PRINT, $tokens[0]->type);
        $this->assertSame(TokenType::STRING, $tokens[1]->type);
        $this->assertSame('hello', $tokens[1]->value);
    }

    public function testTokenizesWhileLoopWithComparisonOperators(): void
    {
        $tokens = Lexer::run('while a < b { a = a + 1; }');

        $this->assertSame(
            [
                TokenType::WHILE,
                TokenType::IDENTIFIER,
                TokenType::LESS,
                TokenType::IDENTIFIER,
                TokenType::LBRACE,
                TokenType::IDENTIFIER,
                TokenType::ASSIGN,
                TokenType::IDENTIFIER,
                TokenType::PLUS,
                TokenType::NUMBER,
                TokenType::SEMICOLON,
                TokenType::RBRACE,
                TokenType::EOF,
            ],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testRecognizesGreaterThanMinusAndMultiplyOperators(): void
    {
        $tokens = Lexer::run('a > b - c * d;');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::GREATER, TokenType::IDENTIFIER, TokenType::MINUS, TokenType::IDENTIFIER, TokenType::MULT, TokenType::IDENTIFIER, TokenType::SEMICOLON, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testSkipsWhitespace(): void
    {
        $tokens = Lexer::run("let  a\n=\t1;");

        $this->assertSame(
            [TokenType::LET, TokenType::IDENTIFIER, TokenType::ASSIGN, TokenType::NUMBER, TokenType::SEMICOLON, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testThrowsOnUnknownCharacter(): void
    {
        $this->expectException(\Exception::class);

        Lexer::run('let a = 1 # 2;');
    }

    public function testTokenizesEqualsOperator(): void
    {
        $tokens = Lexer::run('a == b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::EQUALS, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesNotEqualsOperator(): void
    {
        $tokens = Lexer::run('a != b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::NOTEQUALS, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }
}

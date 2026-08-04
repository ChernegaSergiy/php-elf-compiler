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

    public function testTokenizesNotOperator(): void
    {
        $tokens = Lexer::run('!a');

        $this->assertSame(
            [TokenType::NOT, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesBitwiseAnd(): void
    {
        $tokens = Lexer::run('a & b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::AMP, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesBitwiseOr(): void
    {
        $tokens = Lexer::run('a | b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::BITOR, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesBitwiseXor(): void
    {
        $tokens = Lexer::run('a ^ b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::CARET, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesBitwiseNot(): void
    {
        $tokens = Lexer::run('~a');

        $this->assertSame(
            [TokenType::TILDE, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesShiftLeft(): void
    {
        $tokens = Lexer::run('a << b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::SHL, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesShiftRight(): void
    {
        $tokens = Lexer::run('a >> b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::SHR, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesUnsignedShiftRight(): void
    {
        $tokens = Lexer::run('a >>> b');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::SHRU, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesFnKeyword(): void
    {
        $tokens = Lexer::run('fn main() { }');

        $this->assertSame(
            [TokenType::FN, TokenType::IDENTIFIER, TokenType::LPAREN, TokenType::RPAREN, TokenType::LBRACE, TokenType::RBRACE, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesReturnKeyword(): void
    {
        $tokens = Lexer::run('return 42;');

        $this->assertSame(
            [TokenType::RETURN, TokenType::NUMBER, TokenType::SEMICOLON, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesArrow(): void
    {
        $tokens = Lexer::run('-> i64');

        $this->assertSame(
            [TokenType::ARROW, TokenType::IDENTIFIER, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesFunctionDeclaration(): void
    {
        $tokens = Lexer::run('fn add(a: i64, b: i64) -> i64 { return a + b; }');

        $this->assertSame(
            [
                TokenType::FN,
                TokenType::IDENTIFIER,
                TokenType::LPAREN,
                TokenType::IDENTIFIER,
                TokenType::COLON,
                TokenType::IDENTIFIER,
                TokenType::COMMA,
                TokenType::IDENTIFIER,
                TokenType::COLON,
                TokenType::IDENTIFIER,
                TokenType::RPAREN,
                TokenType::ARROW,
                TokenType::IDENTIFIER,
                TokenType::LBRACE,
                TokenType::RETURN,
                TokenType::IDENTIFIER,
                TokenType::PLUS,
                TokenType::IDENTIFIER,
                TokenType::SEMICOLON,
                TokenType::RBRACE,
                TokenType::EOF,
            ],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }

    public function testTokenizesFunctionCall(): void
    {
        $tokens = Lexer::run('add(1, 2)');

        $this->assertSame(
            [TokenType::IDENTIFIER, TokenType::LPAREN, TokenType::NUMBER, TokenType::COMMA, TokenType::NUMBER, TokenType::RPAREN, TokenType::EOF],
            array_map(static fn ($token) => $token->type, $tokens),
        );
    }
}

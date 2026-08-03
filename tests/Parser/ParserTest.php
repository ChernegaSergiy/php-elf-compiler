<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Parser;

use ChernegaSergiy\TrypilliaCompiler\Ast\AssignStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\BinOpNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\LetStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\NumberNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\PrintStmt;
use ChernegaSergiy\TrypilliaCompiler\Ast\StringNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\VarNode;
use ChernegaSergiy\TrypilliaCompiler\Ast\WhileStmt;
use ChernegaSergiy\TrypilliaCompiler\Lexer\Lexer;
use ChernegaSergiy\TrypilliaCompiler\Parser\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private function parse(string $source): array
    {
        return (new Parser(Lexer::run($source)))->parse();
    }

    public function testParsesLetStatement(): void
    {
        [$stmt] = $this->parse('let a = 15;');

        $this->assertInstanceOf(LetStmt::class, $stmt);
        $this->assertSame('a', $stmt->name);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr);
        $this->assertSame(15, $stmt->expr->val);
    }

    public function testParsesPrintStatementWithStringLiteral(): void
    {
        [$stmt] = $this->parse('print "hi";');

        $this->assertInstanceOf(PrintStmt::class, $stmt);
        $this->assertInstanceOf(StringNode::class, $stmt->expr);
        $this->assertSame('hi', $stmt->expr->val);
    }

    public function testParsesAssignStatement(): void
    {
        [, $stmt] = $this->parse('let a = 1; a = 2;');

        $this->assertInstanceOf(AssignStmt::class, $stmt);
        $this->assertSame('a', $stmt->name);
        $this->assertInstanceOf(NumberNode::class, $stmt->expr);
        $this->assertSame(2, $stmt->expr->val);
    }

    public function testParsesWhileLoopWithBlockBody(): void
    {
        [$stmt] = $this->parse('while a < b { a = a + 1; }');

        $this->assertInstanceOf(WhileStmt::class, $stmt);
        $this->assertInstanceOf(BinOpNode::class, $stmt->condition);
        $this->assertSame('<', $stmt->condition->op);
        $this->assertCount(1, $stmt->body);
        $this->assertInstanceOf(AssignStmt::class, $stmt->body[0]);
    }

    public function testParsesBinaryExpressionWithVariables(): void
    {
        [$stmt] = $this->parse('let c = a + b;');

        $this->assertInstanceOf(BinOpNode::class, $stmt->expr);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->left);
        $this->assertSame('+', $stmt->expr->op);
        $this->assertInstanceOf(VarNode::class, $stmt->expr->right);
    }

    public function testThrowsOnUnexpectedToken(): void
    {
        $this->expectException(\Exception::class);

        $this->parse('+ 1;');
    }

    public function testThrowsWhenAssignIsMissingAfterIdentifier(): void
    {
        $this->expectException(\Exception::class);

        $this->parse('let a 1;');
    }
}

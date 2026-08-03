<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Lexer;

use Exception;

/**
 * Character-by-character lexer for the Trypillia language.
 */
class Lexer
{
    private int $pos = 0;

    /** @var Token[] */
    private array $tokens = [];

    private int $length;

    public function __construct(private string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return Token[]
     */
    public static function run(string $source): array
    {
        $lexer = new self($source);

        return $lexer->tokenize();
    }

    /**
     * @return Token[]
     */
    private function tokenize(): array
    {
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos++];
            if (ctype_space($c)) {
                continue;
            }

            if ($c === '=') {
                if ($this->pos < $this->length && $this->source[$this->pos] === '=') {
                    $this->pos++;
                    $this->tokens[] = new Token(TokenType::EQUALS, '==');
                } else {
                    $this->tokens[] = new Token(TokenType::ASSIGN, '=');
                }
            } elseif ($c === '+') {
                $this->tokens[] = new Token(TokenType::PLUS, '+');
            } elseif ($c === '-') {
                $this->tokens[] = new Token(TokenType::MINUS, '-');
            } elseif ($c === '*') {
                $this->tokens[] = new Token(TokenType::MULT, '*');
            } elseif ($c === '!') {
                if ($this->pos < $this->length && $this->source[$this->pos] === '=') {
                    $this->pos++;
                    $this->tokens[] = new Token(TokenType::NOTEQUALS, '!=');
                } else {
                    $this->tokens[] = new Token(TokenType::NOT, '!');
                }
            } elseif ($c === '<') {
                $this->tokens[] = new Token(TokenType::LESS, '<');
            } elseif ($c === '>') {
                $this->tokens[] = new Token(TokenType::GREATER, '>');
            } elseif ($c === '{') {
                $this->tokens[] = new Token(TokenType::LBRACE, '{');
            } elseif ($c === '}') {
                $this->tokens[] = new Token(TokenType::RBRACE, '}');
            } elseif ($c === ';') {
                $this->tokens[] = new Token(TokenType::SEMICOLON, ';');
            } elseif ($c === '"') {
                $start = $this->pos;
                while ($this->pos < $this->length && $this->source[$this->pos] !== '"') {
                    $this->pos++;
                }
                $val = substr($this->source, $start, $this->pos - $start);
                $this->pos++;
                $this->tokens[] = new Token(TokenType::STRING, $val);
            } elseif (ctype_digit($c)) {
                $val = $c;
                while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                    $val .= $this->source[$this->pos++];
                }
                $this->tokens[] = new Token(TokenType::NUMBER, $val);
            } elseif (ctype_alpha($c) || $c === '_') {
                $val = $c;
                while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
                    $val .= $this->source[$this->pos++];
                }
                $type = match ($val) {
                    'let' => TokenType::LET,
                    'print' => TokenType::PRINT,
                    'while' => TokenType::WHILE,
                    'if' => TokenType::IF,
                    'else' => TokenType::ELSE,
                    default => TokenType::IDENTIFIER,
                };
                $this->tokens[] = new Token($type, $val);
            } else {
                throw new Exception("Unknown character: $c");
            }
        }
        $this->tokens[] = new Token(TokenType::EOF, '');

        return $this->tokens;
    }
}

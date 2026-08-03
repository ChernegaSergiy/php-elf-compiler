<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Lexer;

/**
 * All lexical token kinds recognized by the Trypillia lexer.
 */
enum TokenType
{
    case LET;
    case PRINT;
    case WHILE;
    case IF;
    case ELSE;
    case NOT;
    case IDENTIFIER;
    case NUMBER;
    case STRING;
    case ASSIGN;
    case PLUS;
    case MINUS;
    case MULT;
    case LESS;
    case GREATER;
    case EQUALS;
    case NOTEQUALS;
    case SEMICOLON;
    case LBRACE;
    case RBRACE;
    case EOF;
}

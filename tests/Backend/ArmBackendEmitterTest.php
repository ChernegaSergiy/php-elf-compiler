<?php

declare(strict_types=1);

namespace ChernegaSergiy\TrypilliaCompiler\Tests\Backend;

use ChernegaSergiy\TrypilliaCompiler\Backend\Arm32BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Backend\Arm64BackendEmitter;
use ChernegaSergiy\TrypilliaCompiler\Midend\Instruction;
use ChernegaSergiy\TrypilliaCompiler\Midend\Program;
use PHPUnit\Framework\TestCase;

class ArmBackendEmitterTest extends TestCase
{
    private function simpleProgram(): Program
    {
        $program = new Program();
        $program->add(new Instruction('const_int', '%1', [42]));
        $program->add(new Instruction('store_var', null, ['x', '%1']));
        $program->add(new Instruction('load_var', '%2', ['x']));
        $program->add(new Instruction('print_num', null, ['%2']));

        return $program;
    }

    public function testArm64BackendRaisesUntilBinaryEmissionIsImplemented(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ARM64 machine code emission is not implemented yet.');

        (new Arm64BackendEmitter())->emit($this->simpleProgram(), sys_get_temp_dir() . '/arm64-test.bin');
    }

    public function testArm32BackendRaisesUntilBinaryEmissionIsImplemented(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ARM32 machine code emission is not implemented yet.');

        (new Arm32BackendEmitter())->emit($this->simpleProgram(), sys_get_temp_dir() . '/arm32-test.bin');
    }
}

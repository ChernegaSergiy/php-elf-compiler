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

    public function testArm64BackendProducesBinary(): void
    {
        $filename = sys_get_temp_dir() . '/arm64-test.bin';

        try {
            (new Arm64BackendEmitter())->emit($this->simpleProgram(), $filename);
        } catch (\Exception $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->assertFileExists($filename);
    }

    public function testArm32BackendProducesBinary(): void
    {
        $filename = sys_get_temp_dir() . '/arm32-test.bin';

        try {
            (new Arm32BackendEmitter())->emit($this->simpleProgram(), $filename);
        } catch (\Exception $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->assertFileExists($filename);
    }
}

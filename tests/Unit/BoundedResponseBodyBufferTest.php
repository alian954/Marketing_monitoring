<?php

namespace Tests\Unit;

use App\Services\Probe\BoundedResponseBodyBuffer;
use PHPUnit\Framework\TestCase;

class BoundedResponseBodyBufferTest extends TestCase
{
    public function test_buffer_keeps_only_the_configured_byte_prefix_when_a_chunk_exceeds_the_limit(): void
    {
        $buffer = new BoundedResponseBodyBuffer(16);

        $this->assertTrue($buffer->append('Campaign'));
        $this->assertFalse($buffer->append(' &amp; special offer'));
        $this->assertSame('Campaign &amp; s', $buffer->body());
        $this->assertTrue($buffer->exceededLimit());
        $this->assertFalse($buffer->append('ignored'));
        $this->assertSame(16, strlen($buffer->body()));
    }
}

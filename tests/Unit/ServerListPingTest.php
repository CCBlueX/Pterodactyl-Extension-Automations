<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\ServerListPing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServerListPingTest extends TestCase
{
    /** A status answer as a 1.21.4 server sends it: length 221, packet 0x00, JSON length 218, JSON. */
    private const string RECORDED = 'dd0100da017b2276657273696f6e223a7b226e616d65223a22312e32312e34222c2270726f746f636f6c223a3736397d2c22656e666f7263657353656375726543686174223a747275652c226465736372697074696f6e223a7b2274657874223a2241204d696e65637261667420536572766572227d2c22706c6179657273223a7b226d6178223a32302c226f6e6c696e65223a332c2273616d706c65223a5b7b226e616d65223a225374657665222c226964223a2238363637626137312d623835612d343030342d616635342d343537613937333465656437227d5d7d7d';

    public function test_var_ints_match_the_protocol(): void
    {
        foreach ([0 => '00', 1 => '01', 127 => '7f', 128 => '8001', 255 => 'ff01', 25565 => 'ddc701', 2097151 => 'ffff7f', -1 => 'ffffffff0f'] as $value => $hex) {
            $this->assertSame($hex, bin2hex(ServerListPing::varInt($value)), "VarInt {$value}");
        }
    }

    public function test_the_request_is_a_handshake_followed_by_a_status_request(): void
    {
        $this->assertSame(
            '13'.'00'.'ffffffff0f'.'09'.bin2hex('localhost').'63dd'.'01'.'0100',
            bin2hex(ServerListPing::request('localhost', 25565)),
        );
    }

    public function test_it_reads_a_recorded_answer(): void
    {
        $status = ServerListPing::response($this->memory(hex2bin(self::RECORDED)));

        $this->assertSame(3, $status['players']['online']);
        $this->assertSame('1.21.4', $status['version']['name']);
    }

    public function test_it_talks_to_a_server_over_tcp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $this->assertNotFalse($server, $message);
        $port = (int) mb_substr((string) stream_socket_get_name($server, false), mb_strrpos((string) stream_socket_get_name($server, false), ':') + 1);

        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2);
        $connection = stream_socket_accept($server, 2);
        // The answer waits in the client's receive buffer until the ping reads it.
        fwrite($connection, hex2bin(self::RECORDED));

        $status = ServerListPing::exchange($client, '127.0.0.1', $port);
        $received = fread($connection, 1024);

        $this->assertSame(3, $status['players']['online']);
        $this->assertSame(bin2hex(ServerListPing::request('127.0.0.1', $port)), bin2hex($received));

        fclose($client);
        fclose($connection);
        fclose($server);
    }

    public function test_it_counts_players_end_to_end(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl to run a fake server.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) mb_substr($name, mb_strrpos($name, ':') + 1);

        $child = pcntl_fork();

        if ($child === 0) {
            $connection = stream_socket_accept($server, 5);
            fread($connection, 1024);
            fwrite($connection, hex2bin(self::RECORDED));
            fclose($connection);
            exit(0);
        }

        try {
            $this->assertSame(3, ServerListPing::players('127.0.0.1', $port, 2.0));
        } finally {
            pcntl_waitpid($child, $status);
            fclose($server);
        }
    }

    public function test_broken_answers_are_refused(): void
    {
        $answers = [
            'truncated' => mb_substr(hex2bin(self::RECORDED), 0, 40, '8bit'),
            'wrong packet' => "\x03\x01\x01{",
            'not json' => "\x05\x00\x03abc",
            'too long' => ServerListPing::varInt(300 * 1024),
            'empty' => '',
        ];

        foreach ($answers as $name => $bytes) {
            try {
                ServerListPing::response($this->memory($bytes));
                $this->fail("Accepted the {$name} answer.");
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_a_closed_port_is_reported(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to the server');

        ServerListPing::status('127.0.0.1', (int) mb_substr($name, mb_strrpos($name, ':') + 1), 1.0);
    }

    /** @return resource */
    private function memory(string $bytes)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}

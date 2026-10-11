<?php

declare(strict_types=1);

namespace Automations\Support;

use RuntimeException;

/**
 * The status request a Minecraft client sends for its server list, used for player counts.
 */
final class ServerListPing
{
    private const int MAX_RESPONSE_BYTES = 256 * 1024;

    /** @throws RuntimeException */
    public static function players(string $host, int $port, float $timeout = 3.0): int
    {
        return (int) (self::status($host, $port, $timeout)['players']['online'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public static function status(string $host, int $port, float $timeout = 3.0): array
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $socket = @stream_socket_client("tcp://{$address}:{$port}", $code, $message, $timeout);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the server: {$message}");
        }

        try {
            stream_set_timeout($socket, (int) ceil($timeout));

            return self::exchange($socket, $host, $port);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Sends the handshake and status request on an open connection and reads the answer.
     *
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    public static function exchange($stream, string $host, int $port): array
    {
        fwrite($stream, self::request($host, $port));

        return self::response($stream);
    }

    /** Handshake (protocol -1, next state status) followed by an empty status request. */
    public static function request(string $host, int $port): string
    {
        $handshake = "\x00".self::varInt(-1).self::varInt(mb_strlen($host, '8bit')).$host.pack('n', $port).self::varInt(1);

        return self::varInt(mb_strlen($handshake, '8bit')).$handshake."\x01\x00";
    }

    /**
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    public static function response($stream): array
    {
        $length = self::readVarInt($stream);

        if ($length <= 0 || $length > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('The server sent an invalid status length.');
        }

        $packet = self::read($stream, $length);
        $offset = 0;

        if (self::varIntAt($packet, $offset) !== 0) {
            throw new RuntimeException('The server sent an unexpected packet.');
        }

        $size = self::varIntAt($packet, $offset);
        $status = $size > 0 && $offset + $size <= $length ? json_decode(mb_substr($packet, $offset, $size, '8bit'), true) : null;

        if (! is_array($status)) {
            throw new RuntimeException('The server sent an invalid status.');
        }

        return $status;
    }

    public static function varInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $bytes;
    }

    private static function varIntAt(string $data, int &$offset): int
    {
        $value = 0;

        for ($shift = 0; $shift < 35; $shift += 7) {
            if (! isset($data[$offset])) {
                throw new RuntimeException('The server sent a truncated status.');
            }

            $byte = ord($data[$offset++]);
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new RuntimeException('The server sent an invalid number.');
    }

    /** @param resource $stream */
    private static function readVarInt($stream): int
    {
        $bytes = '';

        for ($i = 0; $i < 5; $i++) {
            $bytes .= $byte = self::read($stream, 1);

            if ((ord($byte) & 0x80) === 0) {
                $offset = 0;

                return self::varIntAt($bytes, $offset);
            }
        }

        throw new RuntimeException('The server sent an invalid number.');
    }

    /** @param resource $stream */
    private static function read($stream, int $length): string
    {
        $data = '';

        while (mb_strlen($data, '8bit') < $length) {
            $chunk = fread($stream, $length - mb_strlen($data, '8bit'));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(stream_get_meta_data($stream)['timed_out'] ? 'The server did not answer in time.' : 'The server closed the connection.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}

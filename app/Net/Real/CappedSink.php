<?php

namespace App\Net\Real;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that keeps at most `$maxBytes` of the body in memory.
 * Past the limit it either drops the rest and lets the download finish
 * (`abort: false`) or makes curl stop the transfer (`abort: true`), and
 * remembers that the body was larger.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    public bool $overflowed = false;

    private StreamInterface $stream;

    private int $kept = 0;

    public function __construct(private readonly int $maxBytes, private readonly bool $abort = false)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        $length = strlen($string);
        $room = max(0, $this->maxBytes - $this->kept);

        if ($length <= $room) {
            $this->kept += $length;

            return $this->stream->write($string);
        }

        $this->overflowed = true;

        if ($room > 0) {
            $this->kept += $this->stream->write(substr($string, 0, $room));
        }

        // Curl aborts when a write accepts fewer bytes than it was given.
        return $this->abort ? 0 : $length;
    }
}

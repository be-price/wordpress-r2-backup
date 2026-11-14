<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/**
 * A failed R2 transfer, identified only by its kind and cURL's number: the URL, signature and body never enter this message.
 * Only a connection failure is temporary. TLS failures, callback aborts and transfers stopped on this side must stay visible.
 */
final class R2TransportException extends RuntimeException
{
    public const CONNECTION = 'connection';
    public const TLS = 'tls';
    public const CALLBACK = 'callback';
    public const OVERFLOW = 'overflow';
    public const LOCAL_WRITE = 'local-write';
    public const OTHER = 'other';

    /** Name lookup, refused connection, partial or empty reply, timeout, send or receive failure, HTTP/2 stream errors. */
    private const NUMBERS = [6 => self::CONNECTION, 7 => self::CONNECTION, 16 => self::CONNECTION, 18 => self::CONNECTION, 28 => self::CONNECTION, 52 => self::CONNECTION, 55 => self::CONNECTION, 56 => self::CONNECTION, 92 => self::CONNECTION,
        35 => self::TLS, 51 => self::TLS, 53 => self::TLS, 54 => self::TLS, 58 => self::TLS, 59 => self::TLS, 60 => self::TLS, 64 => self::TLS, 66 => self::TLS, 77 => self::TLS, 80 => self::TLS, 83 => self::TLS, 90 => self::TLS, 91 => self::TLS,
        23 => self::CALLBACK, 42 => self::CALLBACK];

    private int $errno;
    private string $kind;

    public function __construct(int $errno, int $attempt = 1, int $attempts = 1, ?string $stoppedBecause = null)
    {
        $this->errno = $errno;
        $kinds = [self::CONNECTION, self::TLS, self::CALLBACK, self::OVERFLOW, self::LOCAL_WRITE, self::OTHER];
        $this->kind = $stoppedBecause === null ? (self::NUMBERS[$errno] ?? self::OTHER) : (in_array($stoppedBecause, $kinds, true) ? $stoppedBecause : self::OTHER);
        parent::__construct('R2 transport failed (' . $this->kind . ', errno ' . $errno . ', attempt ' . $attempt . ' of ' . $attempts . ').');
    }

    public function errno(): int { return $this->errno; }

    public function kind(): string { return $this->kind; }

    public function isTransient(): bool { return $this->kind === self::CONNECTION; }
}

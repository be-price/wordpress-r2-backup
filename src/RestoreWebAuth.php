<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/**
 * Authority for the independent web entry: a one-time activation code issued on the owner's terminal, then one session.
 * Starting a restore needs a live session and is consumed once. Reading progress and stopping a job stay possible while a job
 * runs, and for a short time after it finishes, without any right to start another.
 */
final class RestoreWebAuth
{
    public const TOKEN_LIFETIME = 600;
    public const IDLE_LIFETIME = 1800;
    public const ABSOLUTE_LIFETIME = 28800;
    public const FINISHED_GRACE = 1800;
    private const TOKEN_ATTEMPTS = 5;

    private string $directory;

    public function __construct(string $stateDirectory)
    {
        $this->directory = FileSafety::privateDirectory($stateDirectory) . '/web';
        if (@lstat($this->directory) === false && !@mkdir($this->directory, 0700)) { throw new RestoreError('有効化の領域を作成できません。'); }
        FileSafety::privateDirectory($this->directory);
    }

    /** Only a CLI process of the directory's owner can issue, and root is refused so a code never crosses accounts. */
    public function issue(): string
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0 || fileowner($this->directory) !== posix_geteuid()) { throw new RestoreError('有効化コードは、状態フォルダーの所有者が端末から発行してください。'); }
        $token = bin2hex(random_bytes(32));
        $this->locked(function () use ($token): void { $this->write('token.json', ['hash' => hash('sha256', $token), 'expiresAt' => time() + self::TOKEN_LIFETIME, 'attempts' => 0]); });
        return $token;
    }

    /** @return string|null a new session id, replacing any earlier session; never one the browser proposed */
    public function activate(string $code): ?string
    {
        return $this->locked(function () use ($code): ?string {
            $token = $this->read('token.json');
            if ($token === null) { return null; }
            if (($token['expiresAt'] ?? 0) < time()) { $this->remove('token.json'); return null; }
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $code) || !hash_equals((string) $token['hash'], hash('sha256', $code))) {
                $token['attempts'] = (int) ($token['attempts'] ?? 0) + 1;
                if ($token['attempts'] >= self::TOKEN_ATTEMPTS) { $this->remove('token.json'); } else { $this->write('token.json', $token); }
                return null;
            }
            $this->remove('token.json');
            $session = bin2hex(random_bytes(32));
            $this->write('session.json', ['hash' => hash('sha256', $session), 'csrf' => bin2hex(random_bytes(32)), 'createdAt' => time(), 'lastActiveAt' => time(), 'startConsumedAt' => null]);
            return $session;
        });
    }

    /** @return array{csrf:string,actor:string,canStart:bool}|null */
    public function session(string $cookie): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $cookie)) { return null; }
        $session = $this->read('session.json');
        if ($session === null || !hash_equals((string) $session['hash'], hash('sha256', $cookie))) { return null; }
        $now = time();
        $canStart = empty($session['startConsumedAt']) && $now < $session['createdAt'] + self::ABSOLUTE_LIFETIME && $now < $session['lastActiveAt'] + self::IDLE_LIFETIME;
        return ['csrf' => $session['csrf'], 'actor' => 'web:' . $session['hash'], 'canStart' => $canStart];
    }

    /** After the right to start has lapsed, the session lives on only to watch or stop what is running or just finished. */
    public function canObserve(array $session, bool $jobActive, ?int $finishedAt): bool
    {
        if ($session['canStart'] || $jobActive || ($finishedAt !== null && time() < $finishedAt + self::FINISHED_GRACE)) { return true; }
        $this->revoke();
        return false;
    }

    public function touch(): void
    {
        $this->locked(function (): void { $session = $this->read('session.json'); if ($session !== null) { $session['lastActiveAt'] = time(); $this->write('session.json', $session); } });
    }

    public function consumeStart(): void
    {
        $this->locked(function (): void { $session = $this->read('session.json'); if ($session !== null) { $session['startConsumedAt'] = time(); $this->write('session.json', $session); } });
    }

    public function revoke(): void
    {
        $this->locked(function (): void { $this->remove('session.json'); $this->remove('token.json'); });
    }

    private function locked(callable $work)
    {
        $lock = @fopen($this->directory . '/auth.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new RestoreError('有効化の状態を更新できません。'); }
        try { return $work(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function read(string $name): ?array
    {
        $bytes = @file_get_contents($this->directory . '/' . $name);
        if (!is_string($bytes)) { return null; }
        $data = json_decode($bytes, true, 4);
        return is_array($data) ? $data : null;
    }

    private function write(string $name, array $data): void
    {
        $path = $this->directory . '/' . $name; $temporary = $path . '.' . bin2hex(random_bytes(6));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) { throw new RestoreError('有効化の状態を更新できません。'); }
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        try { if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes)) { throw new RestoreError('有効化の状態を更新できません。'); } }
        finally { fclose($stream); }
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RestoreError('有効化の状態を更新できません。'); }
    }

    private function remove(string $name): void { $path = $this->directory . '/' . $name; if (@lstat($path) !== false) { unlink($path); } }
}

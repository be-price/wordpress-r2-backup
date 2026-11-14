<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use RuntimeException;

final class SourceRebuilder
{
    private SyntheticHttpRepository $administrator;
    private ObjectReader $reader;

    private AgeProcess $age;

    /** @param string|ObjectReader $readerConnection */
    public function __construct(string $administratorConnection, $readerConnection, AgeProcess $age)
    {
        if (!is_string($readerConnection) && !$readerConnection instanceof ObjectReader) {
            throw new \TypeError('Expected a reader connection path or ObjectReader.');
        }
        $this->age = $age;

        $this->administrator = new SyntheticHttpRepository($administratorConnection);
        $this->reader = is_string($readerConnection) ? new SyntheticHttpRepository($readerConnection) : $readerConnection;
        if ($this->administrator->unitId() !== $this->reader->unitId()) { throw new RuntimeException('Rebuild connections belong to different units.'); }
    }

    public function switch(int $expectedGeneration, $identityStream, array $recipients, string $newPrivateIndexDirectory, string $newWriterConnection): array
    {
        FileSafety::privateDirectory($newPrivateIndexDirectory);
        if (scandir($newPrivateIndexDirectory) !== ['.', '..']) { throw new RuntimeException('Source rebuild requires an empty new index directory.'); }
        FileSafety::privateDirectory(dirname($newWriterConnection)); FileSafety::assertNoLinks($newWriterConnection);
        if (@lstat($newWriterConnection) !== false || @lstat($newWriterConnection . '.activation.json') !== false) {
            throw new RuntimeException('Source activation requires new connection and recovery files.');
        }
        $ticket = $this->administrator->freezeSource($expectedGeneration);
        while (time() < $ticket['readyAt']) { usleep(100000); }
        $barrier = $this->administrator->sourceBarrier($ticket['freezeId']);
        if ($barrier['generation'] !== $ticket['generation'] || $barrier['readyAt'] !== $ticket['readyAt']) {
            throw new RuntimeException('The administrator freeze changed before rebuild.');
        }
        (new Restorer($this->age, $this->reader))->rebuildIndex($identityStream, $recipients, $newPrivateIndexDirectory);
        $cursor = ManagedCapture::sealRebuiltIndex($newPrivateIndexDirectory, $this->reader);
        if ($this->administrator->sourceBarrier($ticket['freezeId']) !== $barrier) { throw new RuntimeException('The frozen inventory changed during rebuild.'); }
        $profile = $this->administrator->newWriterProfile(bin2hex(random_bytes(32)), $expectedGeneration + 1);
        $profileJson = json_encode($profile, JSON_THROW_ON_ERROR);
        $this->writeNewPrivate($newWriterConnection, $profileJson);
        $this->writeNewPrivate($newWriterConnection . '.activation.json', json_encode(['barrier' => $barrier,
            'cursor' => $cursor, 'profileSha256' => hash('sha256', $profileJson)], JSON_THROW_ON_ERROR));
        return $this->recoverActivation($newWriterConnection);
    }

    public function recoverActivation(string $newWriterConnection): array
    {
        $writer = new SyntheticHttpRepository($newWriterConnection);
        if ($writer->unitId() !== $this->administrator->unitId()) { throw new RuntimeException('The activation connection belongs to another unit.'); }
        $profileJson = file_get_contents($newWriterConnection);
        $profile = json_decode($profileJson, true, 8, JSON_THROW_ON_ERROR);
        $intentFile = $newWriterConnection . '.activation.json'; FileSafety::assertRegularFile($intentFile);
        $stat = lstat($intentFile);
        if (($stat['mode'] & 0777) !== 0600 || $stat['size'] > 8192 || realpath($intentFile) !== $intentFile
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('Invalid private activation recovery file.'); }
        $intent = json_decode(file_get_contents($intentFile), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($intent) || array_keys($intent) !== ['barrier', 'cursor', 'profileSha256']
            || !is_string($intent['profileSha256']) || !hash_equals($intent['profileSha256'], hash('sha256', $profileJson))) {
            throw new RuntimeException('The saved activation does not match its staged writer connection.');
        }
        $binding = $writer->sourceBinding();
        if ($binding['generation'] !== $intent['barrier']['generation'] + 1) { throw new RuntimeException('The saved activation generation changed.'); }
        return $this->administrator->activateSource($intent['barrier'], $binding['sourceId'], $intent['cursor'], hash('sha256', $profile['token']), $profile['expiresAt']);
    }

    private function writeNewPrivate(string $path, string $data): void
    {
        FileSafety::privateDirectory(dirname($path)); FileSafety::assertNoLinks($path);
        $previousMask = umask(0077);
        try { $output = @fopen($path, 'x+b'); }
        finally { umask($previousMask); }
        if ($output === false) { throw new RuntimeException('Cannot create a new private activation file.'); }
        try {
            if (!chmod($path, 0600) || fwrite($output, $data) !== strlen($data) || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new RuntimeException('Cannot persist private activation data.');
            }
            $directory = @fopen(dirname($path), 'r');
            if ($directory === false) { throw new RuntimeException('Cannot open activation directory.'); }
            try { if (\WordPressR2Backup\FileSafety::syncDirectory($directory) === false) { throw new RuntimeException('Cannot persist activation directory.'); } }
            finally { fclose($directory); }
        } finally { fclose($output); }
    }
}

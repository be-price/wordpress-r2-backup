<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class ManagedCapture
{
    private string $processNonce;
    private SyntheticHttpRepository $repository;
    private DedupIndex $index;
    private string $jobsDirectory;
    private AgeProcess $age;
    private int $leaseSeconds;

    private function __construct(SyntheticHttpRepository $repository, DedupIndex $index,
        string $jobsDirectory, AgeProcess $age, int $leaseSeconds)
    {
        $this->repository = $repository;
        $this->index = $index;
        $this->jobsDirectory = $jobsDirectory;
        $this->age = $age;
        $this->leaseSeconds = $leaseSeconds;

        $this->processNonce = bin2hex(random_bytes(32));
    }

    public static function open(string $connectionFile, string $indexDirectory, string $jobsDirectory, AgeProcess $age, int $leaseSeconds = 30): self
    {
        if ($leaseSeconds < 1 || $leaseSeconds > UnitLedger::MAX_LEASE_SECONDS) { throw new \InvalidArgumentException('Invalid capture lease duration.'); }
        $repository = new SyntheticHttpRepository($connectionFile);
        if (!$repository->managed()) { throw new CaptureControlException('A managed writer connection is required.'); }
        return new self($repository, DedupIndex::openManaged($indexDirectory, $repository), FileSafety::privateDirectory($jobsDirectory), $age, $leaseSeconds);
    }

    public static function initializeIndex(string $newPrivateIndexDirectory, string $unitId): array
    {
        return DedupIndex::initializeManaged($newPrivateIndexDirectory, $unitId);
    }

    public static function sealRebuiltIndex(string $privateIndexDirectory, ObjectReader $reader): array
    {
        return DedupIndex::sealRebuilt($privateIndexDirectory, $reader);
    }

    public function prepare(string $root, string $sql, array $recipients): string
    {
        return $this->index->withLock(fn (): string => $this->createJob(SnapshotWriter::sourcePlan($root, $sql, $recipients, $this->repository->unitId())));
    }

    public function prepareFrozen(string $privateInputDirectory, array $recipients): string
    {
        return $this->index->withLock(function () use ($privateInputDirectory, $recipients): string {
            $input = SyntheticCapture::verifiedInput($privateInputDirectory, $recipients, $this->repository->unitId());
            return $this->createJob($input['plan'], $input['capture']);
        });
    }

    private function createJob(array $input, ?array $capture = null): string
    {
        return CaptureJob::create($this->jobsDirectory, $input, $this->repository->sourceBinding(), $capture)->id();
    }

    public function run(string $jobId): array
    {
        return $this->index->withLock(function () use ($jobId): array {
            $job = CaptureJob::open($this->jobsDirectory, $jobId);
            if ($job->binding() !== $this->repository->sourceBinding() || $job->input()['unitId'] !== $this->repository->unitId()) {
                throw new CaptureControlException('The capture job belongs to another source or generation.');
            }
            $status = $this->repository->controlStatus();
            $this->index->reconcile($status['head']);
            $manifest = $job->candidateReceipt('manifests/' . $job->snapshotId() . '.age');
            foreach ($status['publications'] as $publication) {
                if (($publication['jobId'] ?? null) === $jobId && ($publication['descriptor']['kind'] ?? null) === 'complete'
                    && ($publication['result'] ?? null) !== null) {
                    $record = $this->repository->completed($job->snapshotId());
                    if ($manifest === null || $record !== ['snapshotId' => $job->snapshotId(), 'manifest' => $manifest]
                        || $publication['result'] !== $record) { throw new CaptureControlException('Completed capture does not match the fixed job candidate.'); }
                    return $job->finish($manifest);
                }
            }
            if ($job->result() !== null) { throw new CaptureControlException('The saved completed job has no matching service completion.'); }
            try {
                $this->repository->acquire($jobId, $this->processNonce, $status['head'], $this->leaseSeconds);
            } catch (CaptureControlException $error) {
                if (($status['lease']['jobId'] ?? null) === $jobId && !$status['frozen']) {
                    throw new CaptureControlException('Capture is waiting for the prior process lease to expire. Retry this same job after service expiry ' . $status['lease']['expiresAt'] . '.', 0, $error);
                }
                throw new CaptureControlException('Capture stopped because its acquisition was rejected by the unit control state.', 0, $error);
            }
            $heartbeat = function (): void { $this->repository->renew($this->leaseSeconds); };
            $this->index->synchronize($status['head'], function (string $eventId, array $from, array $to) use ($heartbeat): void {
                $heartbeat();
                $this->repository->advance($eventId, $from, $to);
            });
            return (new SnapshotWriter($this->age, $this->repository, $this->index))->capturePrepared($job, $heartbeat);
        });
    }
}

<?php
include_once 'job_store.php';
include_once 'batch.php';

class ApiJobs
{
    private JobStore $store;
    public function __construct($data = null) { $this->store = new JobStore(); }
    public function list(): array { return $this->store->listing(); }
    public function clear(): void { $this->store->clear(); }
    public function run(): void
    {
        $deadline = time() + 300;
        while (time() < $deadline && ($job = $this->store->claim())) {
            try {
                $ok = (new Batch())->replay($job['payload']);
                $this->store->finish((int)$job['job_id'], $ok, $job['attempts'], $ok ? '' : 'Provisioning incomplete');
            } catch (Throwable $e) {
                $this->store->finish((int)$job['job_id'], false, $job['attempts'], 'Transport or provisioning error; see redacted log.');
                MyLog()->Append('Retry operation failed (' . get_class($e) . ')');
            }
        }
    }
}

<?php

namespace S3Server\Task;

abstract class BaseTask
{
    protected string $name;
    protected int $lastRunTime = 0;
    protected int $interval;
    protected bool $isRunning = false;

    public function __construct(string $name, int $interval)
    {
        $this->name = $name;
        $this->interval = $interval;
    }

    public function shouldRun(): bool
    {
        return !$this->isRunning && time() - $this->lastRunTime >= $this->interval;
    }

    public function execute(): void
    {
        if (!$this->shouldRun()) {
            return;
        }

        $this->isRunning = true;
        try {
            $this->run();
            $this->lastRunTime = time();
        } catch (\Exception $e) {
            error_log("[Task Error] {$this->name}: " . $e->getMessage());
        } finally {
            $this->isRunning = false;
        }
    }

    abstract protected function run(): void;

    public function getName(): string
    {
        return $this->name;
    }
}

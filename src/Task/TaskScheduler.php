<?php

namespace S3Server\Task;

class TaskScheduler
{
    /** @var BaseTask[] */
    private array $tasks = [];
    private bool $running = false;
    private bool $shouldStop = false;
    
    public function addTask(BaseTask $task): void
    {
        $this->tasks[] = $task;
    }
    
    public function run(): void
    {
        if ($this->running) {
            return;
        }
        
        $this->running = true;
        $this->shouldStop = false;
        
        while (!$this->shouldStop) {
            foreach ($this->tasks as $task) {
                try {
                    if ($task->shouldRun()) {
                        error_log("[Scheduler] Running task: " . $task->getName());
                        $task->execute();
                    }
                } catch (\Exception $e) {
                    error_log("[Scheduler] Task {$task->getName()} failed: " . $e->getMessage());
                }
            }
            
            sleep(1);
        }
        
        $this->running = false;
    }
    
    public function stop(): void
    {
        $this->shouldStop = true;
    }
}

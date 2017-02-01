<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_TaskDispatch
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */
class Driskell_TaskDispatch_Model_ProcessManager
{
    protected $options = array(
        'f' => false,
    );

    protected $signals = array(
        SIGALRM => 'SigAlarm',
        SIGTERM => 'SigTerm',
        SIGINT  => 'SigInt',
        SIGUSR1 => 'SigUsr1',
        SIGHUP  => 'SigHup',
        SIGCHLD => 'SigChld',
    );

    protected $childrenByName = array();
    protected $children = array();

    protected $wasAlarmed = false;
    protected $wasInterrupted = false;

    protected $interruptCallback = null;
    protected $reloadCallback = null;
    protected $userCallback = null;

    public function __construct()
    {
        foreach ($this->signals as $signal => $label) {
            pcntl_signal($signal, array($this, 'handleSignal'));
        }
    }

    public function runSupervisor($isReloading = false)
    {
        cli_set_process_title('magento-daemon [supervisor]');

        $this->supervise();

        // Signal successful startup to parent if we were reloaded
        if ($isReloading && ($ppid = posix_getppid()) != 1) {
            posix_kill($ppid, SIGUSR1);
        }

        while (!$this->wasInterrupted) {
            sleep(1);
            $this->supervise();
        }

        $this->terminateChildren();
    }

    protected function supervise()
    {
        // Register a callback to force restart of the task dispatcher
        $this->setReloadCallback(array($this, 'reloadSupervisor'));

        // Fork child
        $pid = $this->forkExec(
            'dispatcher',
            PHP_BINARY,
            array($this->getTaskDispatchPath(), '-f')
        );

        while (!$this->wasInterrupted && count($this->children) != 0) {
            $this->signalSleep();
        }

        return $this;
    }

    protected function reloadSupervisor()
    {
        $this->terminateChildren();

        $this->setUserCallback(array($this, 'reloadSupervisorSignal'));

        // Try to start a new supervisor
        $pid = $this->forkExec(
            'supervisor',
            PHP_BINARY,
            array($this->getTaskDispatchPath(), '-r')
        );

        while (!$this->wasInterrupted && count($this->children) != 0) {
            $this->signalSleep();
        }
    }

    protected function reloadSupervisorSignal($status)
    {
        // If new supervisor sends a SIGUSR1 and is still running, it's all good
        $this->wasInterrupted = true;
    }

    public function setInterruptCallback($callback)
    {
        $this->interruptCallback = $callback;
        return $this;
    }

    public function setReloadCallback($callback)
    {
        $this->reloadCallback = $callback;
        return $this;
    }

    public function setUserCallback($callback)
    {
        $this->userCallback = $callback;
        return $this;
    }

    public function getRunningTask($name)
    {
        return array_key_exists($name, $this->childrenByName) ?
            $this->childrenByName[$name] : null;
    }

    public function forkExec($name, $path, $args, $callback = null)
    {
        if (array_key_exists($name, $this->childrenByName)) {
            throw new RuntimeException('Task with same name already running');
        }

        $pid = pcntl_fork();

        if ($pid == 0) {
            pcntl_exec($path, $args);
            exit(1);
        }

        $this->childrenByName[$name] = $pid;
        $this->children[$pid] = array(
            'name'     => $name,
            'callback' => $callback,
        );
        return $pid;
    }

    public function terminateChildren()
    {
        return $this->signalChildren(SIGTERM, 30, array($this, 'killChildren'));
    }

    public function killChildren()
    {
        return $this->signalChildren(SIGKILL);
    }

    public function wasInterrupted()
    {
        return $this->wasInterrupted;
    }

    protected function signalChildren($signal, $timeout = null, $timeoutCallback = null)
    {
        foreach ($this->children as $pid => $data) {
            posix_kill($pid, $signal);
        }

        if (isset($timeout)) {
            pcntl_alarm(60);
        }

        while (count($this->children) != 0) {
            $this->signalSleep();
            if (isset($timeout) && $this->wasAlarmed) {
                call_user_func($timeoutCallback);
                $this->wasAlarmed = false;
                return $this;
            }
        }

        return $this;
    }

    public function signalSleep($timeout = 1)
    {
        $now = $this->getMonotonicTime();
        $target = $now + $timeout;
        do {
            sleep($target - $now);
            pcntl_signal_dispatch();
            $now = $this->getMonotonicTime();
        } while ($now < $target && !$this->wasInterrupted);
    }

    public function handleSignal($signal)
    {
        if (!isset($this->signals[$signal])) {
            return;
        }

        $signal = 'handle' . $this->signals[$signal];
        $this->$signal();
        return $this;
    }

    protected function handleSigAlrm()
    {
        $this->wasAlarmed = true;
        return $this;
    }

    protected function handleSigTerm()
    {
        $this->wasInterrupted = true;

        if (isset($this->interruptCallback)) {
            call_user_func($this->interruptCallback);
        }
        return $this;
    }

    protected function handleSigInt()
    {
        return $this->handleSigTerm();
    }

    protected function handleSigHup()
    {
        if (isset($this->reloadCallback)) {
            call_user_func($this->reloadCallback);
        }
        return $this;
    }

    protected function handleSigUsr1()
    {
        if (isset($this->userCallback)) {
            call_user_func($this->userCallback);
        }
        return $this;
    }

    protected function handleSigChld()
    {
        $status = null;
        $pid = pcntl_wait($status, WNOHANG);
        if ($pid <= 0) {
            return $this;
        }

        if (!array_key_exists($pid, $this->children)) {
            return $this;
        }

        if (!isset($this->children[$pid])) {
            return $this;
        }

        if (!pcntl_wifexited($status)) {
            return $this;
        }

        $name = $this->children[$pid]['name'];
        if (isset($this->children[$pid]['callback'])) {
            call_user_func_array(
                $this->children[$pid]['callback'],
                array($name, $pid, $status)
            );
        }

        unset($this->childrenByName[$name]);
        unset($this->children[$pid]);
        return $this;
    }

    public function getTaskDispatchPath()
    {
        return BP . DIRECTORY_SEPARATOR .
            'shell' . DIRECTORY_SEPARATOR . 'taskdispatch.php';
    }

    public function getMonotonicTime()
    {
        return time();
    }
}

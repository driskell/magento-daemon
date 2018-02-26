<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */

/**
 * PCNTL based process manager
 */
class Driskell_Daemon_Model_ProcessManager
{
    /**
     * Signals to monitor
     *
     * @var array
     */
    protected $signals = array(
        SIGALRM => 'SigAlarm',
        SIGTERM => 'SigTerm',
        SIGINT  => 'SigInt',
        SIGUSR1 => 'SigUsr1',
        SIGHUP  => 'SigHup',
        SIGCHLD => 'SigChld',
    );

    /**
     * Index of running children by name
     *
     * @var array
     */
    protected $childrenByName = array();

    /**
     * Index of running children by process identifier
     *
     * @var array
     */
    protected $children = array();

    /**
     * Was an alarm raised?
     *
     * @var boolean
     */
    protected $wasAlarmed = false;

    /**
     * Were we interrupted?
     *
     * @var boolean
     */
    protected $wasInterrupted = false;

    /**
     * Callback to run when a SIGINT or SIGTERM occurs
     *
     * @var callback|null
     */
    protected $interruptCallback = null;

    /**
     * Callback to run when a SIGHUP occurs
     *
     * @var callback|null
     */
    protected $reloadCallback = null;

    /**
     * Callback to run when a SIGUSR1 occurs
     *
     * @var callback|null
     */
    protected $userCallback = null;

    /**
     * Constructor
     * Sets up signal monitoring
     */
    public function __construct()
    {
        foreach ($this->signals as $signal => $label) {
            pcntl_signal($signal, array($this, 'handleSignal'));
        }
    }

    /**
     * Set the interrupt called for SIGTERM and SIGINT
     *
     * @param callback|null $callback
     * @return self
     */
    public function setInterruptCallback($callback)
    {
        $this->interruptCallback = $callback;
        return $this;
    }

    /**
     * Set the interrupt called for SIGHUP
     *
     * @param callback|null $callback
     * @return self
     */
    public function setReloadCallback($callback)
    {
        $this->reloadCallback = $callback;
        return $this;
    }

    /**
     * Set the interrupt called for SIGUSR1
     *
     * @param callback|null $callback
     * @return self
     */
    public function setUserCallback($callback)
    {
        $this->userCallback = $callback;
        return $this;
    }

    /**
     * Return process ID for a running process by name
     * Or return null if the process is not found/running
     *
     * @param string $name
     * @return int|null
     */
    public function getRunningProcess($name)
    {
        return array_key_exists($name, $this->childrenByName) ?
            $this->childrenByName[$name] : null;
    }

    /**
     * Start a new child via the daemon shell script
     * Calls forkExec with the PHP binary as $path and the daemon shell script
     * inserted as the first argument, passing through all other parameters
     *
     * @see forkExec
     * @param string $name
     * @param string[] $args
     * @param callback|null $callback
     * @return void
     */
    public function forkExecDaemon($name, $args, $callback = null)
    {
        array_unshift($args, $this->getDaemonScriptPath());
        $this->forkExec($name, PHP_BINARY, $args, $callback);
    }

    /**
     * Start a new child by forking and exec
     * Will throw RuntimeException if a process with the same name is already running
     * so be sure to call getRunningProcess first
     *
     * @param string $name Name to register the process under for getRunningProcess et. al.
     * @param string $path Path of the child binary
     * @param string[] $args Arguments for the child
     * @param callback|null $callback Callback to run upon the child exiting
     * @throws RuntimeException
     * @return int
     */
    public function forkExec($name, $path, $args, $callback = null)
    {
        if (array_key_exists($name, $this->childrenByName)) {
            throw new RuntimeException('Process with same name already running');
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

    /**
     * Terminate all children
     * Sends a SIGTERM to all children and waits for them to exit for 30
     * seconds before then sending a SIGKILL and waiting indefinitely for
     * them to exit
     *
     * @return self
     */
    public function terminateChildren()
    {
        return $this->signalChildren(SIGTERM, 30, array($this, 'killChildren'));
    }

    /**
     * Kill all children
     * Sends a SIGKILL to all children and waits indefinitely for them to
     * exit
     *
     * @return self
     */
    public function killChildren()
    {
        return $this->signalChildren(SIGKILL);
    }

    /**
     * Returns true if there are any children still running
     *
     * @return boolean
     */
    public function hasChildren()
    {
        return count($this->children) != 0;
    }

    /**
     * Sleep for the requested number of seconds, dispatching signals
     * as and when they are received, and resuming sleep to ensure the
     * requested number of seconds is slept
     *
     * Will return earlier then the requested time, however, if a SIGINT
     * or SIGTERM is received, in which case wasInterrupted will return
     * true
     *
     * @param integer $timeout
     * @return void
     */
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

    /**
     * Returns true if SIGINT occurred during a signalSleep call
     *
     * @return boolean
     */
    public function wasInterrupted()
    {
        return $this->wasInterrupted;
    }

    /**
     * Handle a signal, calling any necessary callbacks
     *
     * @param int $signal
     * @return void
     */
    public function handleSignal($signal)
    {
        if (!isset($this->signals[$signal])) {
            return;
        }

        $signal = 'handle' . $this->signals[$signal];
        $this->$signal();
    }

    /**
     * Return the path to the daemon script
     *
     * @return void
     */
    public function getDaemonScriptPath()
    {
        return BP . DIRECTORY_SEPARATOR .
            'shell' . DIRECTORY_SEPARATOR . 'driskell-daemon.php';
    }

    /**
     * Set the running process's title
     *
     * @param string $title
     * @return void
     */
    public function setProcessTitle($title)
    {
        cli_set_process_title($title);
    }

    /**
     * Return a monotonic time counter
     *
     * This doesn't exist in PHP core at the moment so we crudely
     * will rely on the clock - as dangerous as that could be I
     * believe it will be relatively safe for our use case, and
     * as Magento uses this clock too it's not likely to introduce
     * NEW issues, and rather just manifest issues that already exist
     *
     * @return int
     */
    public function getMonotonicTime()
    {
        return time();
    }

    /**
     * Signals all children with a specific signal and then
     * waits for them to exit. If they do not exit within the
     * given timeout, the timeout callback (if set) is called before
     * immediately returning to caller
     *
     * If no timeout is set, waits indefinitely for the children
     * to exit - ONLY DO THIS FOR SIGKILL
     *
     * @param int $signal
     * @param int|null $timeout
     * @param callback|null $timeoutCallback
     * @return self
     */
    protected function signalChildren($signal, $timeout = null, $timeoutCallback = null)
    {
        foreach ($this->children as $pid => $data) {
            posix_kill($pid, $signal);
        }

        if (isset($timeout)) {
            $this->wasAlarmed = false;
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

        // Cancel the alarm
        pcntl_alarm(0);
        return $this;
    }

    /**
     * Handle SIGALARM
     *
     * @return void
     */
    protected function handleSigAlarm()
    {
        $this->wasAlarmed = true;
    }

    /**
     * Handle SIGTERM
     *
     * @return void
     */
    protected function handleSigTerm()
    {
        $this->wasInterrupted = true;

        if (isset($this->interruptCallback)) {
            call_user_func($this->interruptCallback);
        }
    }

    /**
     * Handle SIGINT
     *
     * @return void
     */
    protected function handleSigInt()
    {
        $this->handleSigTerm();
    }

    /**
     * Handle SIGHUP
     *
     * @return void
     */
    protected function handleSigHup()
    {
        if (isset($this->reloadCallback)) {
            call_user_func($this->reloadCallback);
        }
    }

    /**
     * Handle SIGUSR1
     *
     * @return void
     */
    protected function handleSigUsr1()
    {
        if (isset($this->userCallback)) {
            call_user_func($this->userCallback);
        }
    }

    /**
     * Handle SIGCHILD
     * Updates internal state of running children and calls
     * necessary completion callbacks
     *
     * @return void
     */
    protected function handleSigChld()
    {
        // Process all pending child statuses
        // (SIGCHLD might only trigger once for multiple)
        while (true) {
            $status = null;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid <= 0) {
                return;
            }

            if (!isset($this->children[$pid])) {
                continue;
            }

            if (!pcntl_wifexited($status)) {
                continue;
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
        }
    }
}

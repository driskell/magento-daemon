<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */

/**
 * Supervisor
 * WARNING: Magento is not initialised when this class is in use
 */
class Driskell_Daemon_Model_Supervisor
{
    const SUPERVISOR_INIT = 0;
    const SUPERVISOR_RUNNING = 1;
    const SUPERVISOR_RELOADING = 2;
    const SUPERVISOR_EXITING = 3;

    /**
     * Process manager
     *
     * @var Driskell_Daemon_Model_Processmanager
     */
    private $processManager;

    /**
     * Current status
     *
     * @var int
     */
    private $state = self::SUPERVISOR_INIT;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->processManager = new Driskell_Daemon_Model_Processmanager();
    }

    /**
     * Run the supervisor
     * Restart loop
     *
     * @return void
     */
    public function run()
    {
        $this->processManager->setProcessTitle('driskell-daemon [supervisor]');

        // Become a session leader so we can monitor session via:
        //    ps fj -s <sessionId>
        // We run this in the SysVInit wrapper when we ask for status
        // so we can output running processes for great observability
        posix_setsid();

        $this->state = self::SUPERVISOR_RUNNING;

        $pauseEnabled = false;
        while ($this->state == self::SUPERVISOR_RUNNING) {
            if ($pauseEnabled) {
                sleep(5);
            } else {
                $pauseEnabled = true;
            }

            $pauseEnabled = !$this->supervise();
        }

        $this->processManager->terminateChildren();
    }

    /**
     * Start the child
     * Child monitoring loop
     * Returns true if a reload occurred, otherwise false to indicate a failure
     *
     * @return boolean
     */
    private function supervise()
    {
        // Register a callback to force restart of the dispatcher
        $this->processManager->setReloadCallback(array($this, 'reloadDispatcherSignal'));

        // Fork child
        $this->processManager->forkExecDaemon(
            'dispatcher',
            array('-f')
        );

        while ($this->shouldContinueWithStatus(self::SUPERVISOR_RUNNING)) {
            $this->processManager->signalSleep();
        }

        $this->processManager->setReloadCallback(null);

        // Were we requested to restart the dispatcher?
        if ($this->state == self::SUPERVISOR_RELOADING) {
            $this->processManager->terminateChildren();
            $this->state = self::SUPERVISOR_RUNNING;
            return true;
        }

        // TODO: Log failures somewhere?
        return false;
    }

    /**
     * Log the request to restart the dispatcher
     *
     * @return void
     */
    public function reloadDispatcherSignal()
    {
        // Ignore repeated signals
        if ($this->state == self::SUPERVISOR_RELOADING) {
            return;
        }

        $this->state = self::SUPERVISOR_RELOADING;
    }

    /**
     * Return true if the requested status is still correct and we have running
     * children (i.e., they haven't failed) and we haven't been interrupted
     *
     * @param int $status
     * @return boolean
     */
    private function shouldContinueWithStatus($status)
    {
        if ($this->processManager->wasInterrupted()) {
            // If we were interrupted, change status to exiting
            $this->state = self::SUPERVISOR_EXITING;
            return false;
        }
        if ($this->state != $status) {
            return false;
        }
        if (!$this->processManager->hasChildren()) {
            return false;
        }
        return true;
    }
}

<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */
class Driskell_Daemon_Model_Dispatcher extends Mage_Cron_Model_Observer
{
    /**
     * Configuration
     *
     * @var Driskell_Daemon_Model_Config
     */
    private $config;

    /**
     * Process manager instance
     *
     * @var Driskell_Daemon_Model_Processmanager
     */
    private $processManager;

    /**
     * Temp data directory for daemon to save logs etc. to
     *
     * @var string
     */
    private $varDir;

    /**
     * List of currently active parallel jobs
     *
     * @var array
     */
    private $runningJobs = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = Mage::getModel('driskell_daemon/config');
        $this->processManager = Mage::getSingleton('driskell_daemon/processmanager');
        $this->varDir = Mage::app()->getConfig()->getVarDir('daemon');
    }

    /**
     * Runs the main dispatcher
     * Called by the supervisor (which in turn is called by the shell script)
     * Should never exit until signalled
     * Restarted by the supervisor when needed
     *
     * @return void
     */
    public function runDispatcher()
    {
        // Make ourself recognisable in the process list
        $this->processManager->setProcessTitle('driskell-daemon [dispatcher]');

        // Setup data directory
        if (!file_exists($this->varDir)) {
            mkdir($this->varDir, 0777, true);
        }

        $this->cleanBlackBox();

        // Prevent collection caching so we can reload the schedule reliably
        Mage::app()->getCacheInstance()->banUse('collections');

        $this->processManager->signalSleep($this->timeUntilNextRun());
        while (!$this->processManager->wasInterrupted()) {
            // Start children for always and default (it'll skip if already running)
            $this->forkChildProcess('always', array($this, 'alwaysProcessCompleted'));
            $this->forkChildProcess('default', array($this, 'defaultProcessCompleted'));

            // Start parallel jobs
            $this->startParallel();

            $this->processManager->signalSleep($this->timeUntilNextRun());
        }

        // Interrupte received, wait for children to end
        $this->processManager->terminateChildren();
    }

    /**
     * Callback when the always process completes
     *
     * @param string $name
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function alwaysProcessCompleted($name, $pid, $status)
    {
        $this->batchedProcessCompleted('always', $name, $pid, $status);
    }

    /**
     * Callback when the default process completes
     *
     * @param string $name
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function defaultProcessCompleted($name, $pid, $status)
    {
        $this->batchedProcessCompleted('default', $name, $pid, $status);
    }

    /**
     * Callback when a batched process completes
     * (Called via defaultProcessCompleted and alwaysProcessCompleted)
     *
     * @param string $jobType
     * @param string $name
     * @param int $pid
     * @param int $status
     * @return void
     */
    private function batchedProcessCompleted($jobType, $name, $pid, $status)
    {
        $exitCode = pcntl_wexitstatus($status);
        if (!pcntl_wifsignaled($status) && $exitCode == 0) {
            // Successful, all OK
            return;
        }

        // An error occurred, pull up the last run job and flag it as failed
        $scheduleId = $this->config->getActiveJob($jobType);
        if (!$scheduleId) {
            return;
        }

        $this->config->clearActiveJob($jobType);

        $schedule = Mage::getModel('cron/schedule')->load($scheduleId);
        if (!$schedule) {
            return;
        }

        $schedule->setMessages(
            Mage::helper('cron')->__('Job failed with exit code: %d', $exitCode)
        );

        // Collect any recorded logs into the given schedule messages
        $this->processBlackBox($schedule, $pid);

        $schedule
            ->setStatus(Mage_Cron_Model_Schedule::STATUS_ERROR)
            ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->save();
    }

    /**
     * Calculated seconds to wait before running next batch
     *
     * @return int
     */
    private function timeUntilNextRun()
    {
        return 60 - intval(date('s'));
    }

    /**
     * Runs a specific process
     * Called by the shell script
     *
     * @param array $args
     * @return void
     */
    public function runProcess(array $args)
    {
        // Are we running the default/always processes?
        if ($args['f'] == 'always') {
            $this->runAlways();
            return;
        } else if ($args['f'] == 'default') {
            $this->runDefault();
            return;
        }

        $scheduleId = intval($args['f']);
        if (strval($scheduleId) !== $args['f']) {
            Mage::throwException(Mage::helper('cron')->__('Invalid job dispatch call.'));
        }

        $schedule = Mage::getModel('cron/schedule')->load($scheduleId);
        if (!$schedule) {
            Mage::throwException(Mage::helper('cron')->__('Invalid schedule reference.'));
        }

        // Most of this is now a mixture of the default dispatch function in
        // Cron observer, and the job processor, and a bit of the generate function
        $jobsRoot = Mage::getConfig()->getNode('crontab/jobs');
        $defaultJobsRoot = Mage::getConfig()->getNode('default/crontab/jobs');
        $jobConfig = $jobsRoot->{$schedule->getJobCode()};
        if (!$jobConfig || !$jobConfig->run) {
            $jobConfig = $defaultJobsRoot->{$schedule->getJobCode()};
            if (!$jobConfig || !$jobConfig->run) {
                Mage::throwException(Mage::helper('cron')->__('Invalid schedule reference, job configuration not found.'));
            }
        }

        $runConfig = $jobConfig->run;

        if ($runConfig->model) {
            if (!preg_match(self::REGEX_RUN_MODEL, (string)$runConfig->model, $run)) {
                Mage::throwException(Mage::helper('cron')->__('Invalid model/method definition, expecting "model/class::method".'));
            }
            if (!($model = Mage::getModel($run[1])) || !method_exists($model, $run[2])) {
                Mage::throwException(Mage::helper('cron')->__('Invalid callback: %s::%s does not exist', $run[1], $run[2]));
            }
            $callback = array($model, $run[2]);
            $arguments = array($schedule);
        }
        if (empty($callback)) {
            Mage::throwException(Mage::helper('cron')->__('No callbacks found'));
        }

        $this->setScheduleCliTitle($schedule, $jobConfig);
        $this->setupBlackBox();
        call_user_func_array($callback, $arguments);
    }

    /**
     * Run the 'always' Magento cron via observer
     *
     * @param string $event The event to dispatch
     * @return void
     */
    private function runAlways()
    {
        $this->processManager->setProcessTitle('driskell-daemon [always - observers]');
        $this->dispatchEventObservers('always');
        $this->dispatchAlways();
    }

    /**
     * Run the 'default' Magento cron via observer
     * Then process scheduled jobs
     *
     * @return void
     */
    private function runDefault()
    {
        $this->processManager->setProcessTitle('driskell-daemon [default - observers]');
        $this->dispatchEventObservers('default');

        // For default cron that runs schedules, drop off any schedule that is
        // marked as parallel, as those are managed by the dispatcher
        $pendingSchedules = $this->getPendingSchedules();
        foreach ($pendingSchedules->getItems() as $schedule) {
            if ($this->config->isJobParallel($schedule->getJobCode())) {
                $pendingSchedules->removeItemByKey($schedule->getId());
            }
        }

        // Process remaining jobs
        $this->dispatch();
    }

    /**
     * Dispatch event observers for a specific event
     * Excludes the cron scheduler
     *
     * @param string $eventName
     * @return void
     */
    private function dispatchEventObservers($eventName)
    {
        $observerList = Mage::getConfig()->getEventConfig('crontab', $eventName)->observers->children();
        foreach ($observerList as $name => $observer) {
            if ($name == 'cron_observer') {
                // We handle running of scheduled crons ourselves separately to other
                // observers that want to run
                continue;
            }

            switch ((string)$observer->type) {
                case 'singleton':
                    $callback = array(
                        Mage::getSingleton((string)$observer->class),
                        (string)$observer->method
                    );
                    break;
                case 'object':
                case 'model':
                    $callback = array(
                        Mage::getModel((string)$observer->class),
                        (string)$observer->method
                    );
                    break;
                default:
                    $callback = array($observer->getClassName(), (string)$observer->method);
                    break;
            }

            $event = new Varien_Event();
            $event->setName($eventName);
            $observer = new Varien_Event_Observer();
            $observer->setData(array('event' => $event));
            call_user_func($callback, $observer);
        }
    }

    /**
     * Start parallel jobs
     *
     * @return void
     */
    private function startParallel()
    {
        $parallelJobs = array();
        $pendingSchedules = $this->getPendingSchedules();
        foreach ($pendingSchedules->getItems() as $schedule) {
            if ($this->config->isJobParallel($schedule->getJobCode())) {
                $parallelJobs[] = $schedule;
            }
        }

        // Pending schedule collection is cached so clear it
        $this->resetPendingSchedules();

        foreach ($parallelJobs as $schedule) {
            $jobsRoot = Mage::getConfig()->getNode('crontab/jobs');
            $defaultJobsRoot = Mage::getConfig()->getNode('default/crontab/jobs');
            $jobConfig = $jobsRoot->{$schedule->getJobCode()};
            if (!$jobConfig || !$jobConfig->run) {
                $jobConfig = $defaultJobsRoot->{$schedule->getJobCode()};
                if (!$jobConfig || !$jobConfig->run) {
                    continue;
                }
            }

            if (!$this->prepareJob($schedule, $jobConfig, false)) {
                continue;
            }

            $this->runningJobs[$schedule->getJobCode()] = $schedule;
            $this->forkChildProcess(
                $schedule->getId(),
                array($this, 'parallelJobCompleted'),
                $schedule->getJobCode()
            );
        }
    }

    /**
     * Callback when a parallel job completes
     *
     * @param string $name
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function parallelJobCompleted($name, $pid, $status)
    {
        $schedule = $this->runningJobs[$name];
        unset($this->runningJobs[$name]);

        $errorStatus = Mage_Cron_Model_Schedule::STATUS_SUCCESS;

        $exitCode = pcntl_wexitstatus($status);
        if (pcntl_wifsignaled($status) || $exitCode != 0) {
            $errorStatus = Mage_Cron_Model_Schedule::STATUS_ERROR;
            $schedule->setMessages(
                Mage::helper('cron')->__('Job failed with exit code: %d', $exitCode)
            );
        }

        // Collect any recorded logs into the given schedule messages
        $this->processBlackBox($schedule, $pid);

        $schedule
            ->setStatus($errorStatus)
            ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->save();
    }

    /**
     * Reset pending schedules cache
     *
     * @return void
     */
    private function resetPendingSchedules()
    {
        $this->_pendingSchedules = null;
    }

    /**
     * Set CLI title for a schedule
     *
     * @param Magento_Cron_Model_Schedule $schedule
     * @param Varien_Simplexml_Element $jobConfig
     * @param string|null $prefix
     * @return void
     */
    private function setScheduleCliTitle(
        Magento_Cron_Model_Schedule $schedule,
        Varien_Simplexml_Element $jobConfig,
        $prefix = null
    ) {
        // It's useful to output the schedule on process list
        $cronExpr = null;
        if ($jobConfig->schedule->config_path) {
            $cronExpr = Mage::getStoreConfig((string)$jobConfig->schedule->config_path);
        }
        if (empty($cronExpr) && $jobConfig->schedule->cron_expr) {
            $cronExpr = (string)$jobConfig->schedule->cron_expr;
        }
        if ($cronExpr) {
            $cronExpr = ' - ' . $cronExpr;
        } else {
            $cronExpr = '';
        }

        if (!empty($prefix)) {
            $prefix .= ' - ';
        }

        $this->processManager->setProcessTitle('driskell-daemon [' . $prefix . $schedule->getJobCode() . $cronExpr . ']');
    }

    /**
     * Fork a child to run a set of jobs
     *
     * @param string $name
     * @param callback|null $completionCallback
     * @param string|null $identifier
     * @return void
     */
    private function forkChildProcess($name, $completionCallback = null, $identifier = null)
    {
        if (empty($identifier)) {
            $identifier = $name;
        }

        // Check if already running
        $existingProcess = $this->processManager->getRunningProcess($identifier);
        if (isset($existingProcess)) {
            // TODO: Timeout?
            return;
        }

        $this->processManager->forkExecDaemon(
            $identifier,
            array(
                '-f',
                $name
            ),
            $completionCallback
        );
    }

    /**
     * Cleanup orphaned blackboxes
     *
     * @return void
     */
    private function cleanBlackBox()
    {
        $blackBoxDir = opendir($this->varDir);
        while (true) {
            $fileName = readdir($blackBoxDir);
            if ($fileName === false) {
                break;
            }
            if ($filename == '.' || $filename == '..') {
                continue;
            }
            if (preg_match('/^blackbox-[0-9]*\\.log$/i', $filename)) {
                unlink($this->varDir . DS . $filename);
            }
        }
    }

    /**
     * Initialise logging of errors
     * Allows us to track warning out and even fatal errors from the
     * contents of this file, which will be picked up by the dispatcher
     * after we exit
     *
     * @return void
     */
    private function setupBlackBox()
    {
        $blackBox = $this->varDir . DS . 'blackbox-' . getmypid() . '.log';
        unlink($blackBox);
        ini_set('display_errors', 1);
        ini_set('error_log', $blackBox);
    }

    /**
     * Record messages from a black box to the given schedule
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @param int $pid
     * @return void
     */
    private function processBlackBox(Mage_Cron_Model_Schedule $schedule, $pid = 0)
    {
        if ($pid == 0) {
            $pid = getmypid();
        }

        $blackBox = $this->varDir . DS . 'blackbox-' . $pid . '.log';
        if (!file_exists($blackBox)) {
            return;
        }
        if (filesize($blackBox) == 0) {
            unlink($blackBox);
            return;
        }

        $messages = file_get_contents($blackBox);
        unlink($blackBox);
        if ($schedule->getMessages()) {
            $messages = $schedule->getMessages() . PHP_EOL . PHP_EOL . $messages;
        }
        $schedule->setMessages($messages);
    }

    /**
     * Override default process job function so we can store last ran job
     * and flag failure of correct one in the dispatcher if a failure occurs
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @param $jobConfig
     * @param bool $isAlways
     * @return Mage_Cron_Model_Observer
     */
    protected function _processJob($schedule, $jobConfig, $isAlways = false)
    {
        $jobType = $isAlways ? 'always' : 'default';
        $this->setScheduleCliTitle($schedule, $jobConfig, $jobType);

        $callback = $this->prepareJob($schedule, $jobConfig, $isAlways);
        if (!$callback) {
            return $this;
        }

        $this->config->setActiveJob($jobType, $schedule->getId());
        $this->setupBlackBox();
        $errorStatus = Mage_Cron_Model_Schedule::STATUS_ERROR;
        try {
            call_user_func($callback, $schedule);
            $errorStatus = Mage_Cron_Model_Schedule::STATUS_SUCCESS;
        } catch (Exception $e) {
            $schedule->setMessages($e->__toString());
        }
        $this->processBlackBox($schedule);
        $this->config->clearActiveJob($jobType);

        $schedule
            ->setStatus($errorStatus)
            ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->save();
        return $this;
    }

    /**
     * Prepare job to run
     * If returns null, the schedule is not yet due or failed to start
     * If returns a callback, the schedule is prepared and marked running
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @param Varien_SimpleXml_Element $jobConfig
     * @param boolean $isAlways
     * @throws Mage_Core_Exception
     * @return callback|null
     */
    private function prepareJob(Mage_Cron_Model_Schedule $schedule, Varien_SimpleXml_Element $jobConfig, $isAlways = false)
    {
        // Most of this code is pulled from original _processJob
        // We re-use it for job preparation of both parallel and default
        $runConfig = $jobConfig->run;
        if (!$isAlways) {
            $scheduleLifetime = Mage::getStoreConfig(self::XML_PATH_SCHEDULE_LIFETIME) * 60;
            $now = time();
            $time = strtotime($schedule->getScheduledAt());
            if ($time > $now) {
                return null;
            }
        }

        $errorStatus = Mage_Cron_Model_Schedule::STATUS_ERROR;
        try {
            if (!$isAlways) {
                if ($time < $now - $scheduleLifetime) {
                    $errorStatus = Mage_Cron_Model_Schedule::STATUS_MISSED;
                    Mage::throwException(Mage::helper('cron')->__('Too late for the schedule.'));
                }
            }
            if ($runConfig->model) {
                if (!preg_match(self::REGEX_RUN_MODEL, (string)$runConfig->model, $run)) {
                    Mage::throwException(Mage::helper('cron')->__('Invalid model/method definition, expecting "model/class::method".'));
                }
                if (!($model = Mage::getModel($run[1])) || !method_exists($model, $run[2])) {
                    Mage::throwException(Mage::helper('cron')->__('Invalid callback: %s::%s does not exist', $run[1], $run[2]));
                }
                $callback = array($model, $run[2]);
            }

            if (!$isAlways) {
                if (!$schedule->tryLockJob()) {
                    // another cron started this job intermittently, so skip it
                    return null;
                }
                /**
                though running status is set in tryLockJob we must set it here because the object
                was loaded with a pending status and will set it back to pending if we don't set it here
                 */
            }

            $schedule
                ->setExecutedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
                ->save();
        } catch (Exception $e) {
            $schedule
                ->setStatus($errorStatus)
                ->setMessages($e->__toString())
                ->save();
            return null;
        }

        return $callback;
    }
}

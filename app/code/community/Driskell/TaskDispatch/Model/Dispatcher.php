<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_TaskDispatch
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */
class Driskell_TaskDispatch_Model_Dispatcher extends Mage_Cron_Model_Observer
{
    protected $eventObservers = array();

    protected $runningTasks = array();

    public function __construct()
    {
        $this->processManager = Mage::getSingleton('driskell_taskdispatch/processmanager');
    }

    public function runDispatcher()
    {
        cli_set_process_title('magento-daemon [dispatcher]');

        $this->loadAllEventObservers();

        $this->processManager->signalSleep($this->timeUntilNextRun());
        while (!$this->processManager->wasInterrupted()) {
            // Trigger always/default on their own children
            $this->dispatchAllEventObservers();

            $this->dispatchAlways();
            $this->dispatch();

            $this->processManager->signalSleep($this->timeUntilNextRun());
        }

        $this->processManager->terminateChildren();

        return $this;
    }

    public function runTask($args)
    {
        foreach (array('always', 'default') as $eventName) {
            if (strncasecmp($args['f'], $eventName . '_', strlen($eventName) + 1) == 0) {
                $this->loadEventObservers($eventName);
                if (isset($this->eventObservers[$name])) {
                    cli_set_process_title('magento-daemon [' . $args['f'] . ']');
                    call_user_func($this->eventObservers[$name]);
                    return;
                }
            }
        }

        if (!preg_match('#^schedule_(\d+)$#i', $args['f'], $matches) || !isset($args['m'])) {
            Mage::throwException(Mage::helper('cron')->__('Invalid task dispatch call.'));
        }

        $schedule = Mage::getModel('cron/schedule')->load($matches[1]);
        if (!$schedule) {
            Mage::throwException(Mage::helper('cron')->__('Invalid schedule reference.'));
        }

        if (!preg_match(self::REGEX_RUN_MODEL, $args['m'], $run)) {
            Mage::throwException(Mage::helper('cron')->__('Invalid model/method definition, expecting "model/class::method".'));
        }
        if (!($model = Mage::getModel($run[1])) || !method_exists($model, $run[2])) {
            Mage::throwException(Mage::helper('cron')->__('Invalid callback: %s::%s does not exist', $run[1], $run[2]));
        }

        cli_set_process_title('magento-daemon [' . $schedule->getJobCode() . ']');
        call_user_func_array(array($model, $run[2]), array($schedule));
    }

    protected function timeUntilNextRun()
    {
        return 60 - intval(date('s'));
    }

    protected function loadAllEventObservers()
    {
        foreach (array('default', 'always') as $eventName) {
            $this->loadEventObservers($eventName);
        }
        return $this;
    }

    protected function loadEventObservers($eventName)
    {
        $this->eventObservers[$eventName] = array();

        $observerList = Mage::getConfig()->getEventConfig('crontab', $eventName)->observers->children();
        foreach ($observerList as $name => $observer) {
            if ($name == 'cron_observer') {
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

            $this->eventObservers[$eventName][$name] = $callback;
        }

        return $this;
    }

    protected function dispatchAllEventObservers()
    {
        foreach (array('default', 'always') as $eventName) {
            $this->dispatchEventObservers($eventName);
        }
        return $this;
    }

    protected function dispatchEventObservers($eventName)
    {
        foreach ($this->eventObservers[$eventName] as $name => $callback) {
            // Check if already running
            $existingTask = $this->processManager->getRunningTask($name);
            if (isset($existingTask)) {
                // TODO: Timeout?
                continue;
            }

            $this->processManager->forkExec(
                $eventName . '_' . $name,
                PHP_BINARY,
                array(
                    $this->processManager->getTaskDispatchPath(),
                    '-f',
                    $eventName . '_' . $name
                )
            );
        }

        return $this;
    }

    /**
     * Process cron task
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @param $jobConfig
     * @param bool $isAlways
     * @return Mage_Cron_Model_Observer
     */
    protected function _processJob($schedule, $jobConfig, $isAlways = false)
    {
        $runConfig = $jobConfig->run;
        if (!$isAlways) {
            $scheduleLifetime = Mage::getStoreConfig(self::XML_PATH_SCHEDULE_LIFETIME) * 60;
            $now = time();
            $time = strtotime($schedule->getScheduledAt());
            if ($time > $now) {
                return;
            }
        }

        $errorStatus = Mage_Cron_Model_Schedule::STATUS_ERROR;
        try {
            if ($this->processManager->getRunningTask($schedule->getJobCode())) {
                $errorStatus = Mage_Cron_Model_Schedule::STATUS_MISSED;
                Mage::throwException(Mage::helper('cron')->__('Task is already running.'));
            }
            if (!$isAlways) {
                if ($time < $now - $scheduleLifetime) {
                    $errorStatus = Mage_Cron_Model_Schedule::STATUS_MISSED;
                    Mage::throwException(Mage::helper('cron')->__('Too late for the schedule.'));
                }
            }
            if (!$runConfig->model) {
                Mage::throwException(Mage::helper('cron')->__('No callbacks found'));
            }

            if (!$isAlways) {
                if (!$schedule->tryLockJob()) {
                    // another cron started this job intermittently, so skip it
                    return;
                }
                /**
                though running status is set in tryLockJob we must set it here because the object
                was loaded with a pending status and will set it back to pending if we don't set it here
                 */
            }

            $schedule
                ->setExecutedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
                ->save();

            $this->runningTasks[$schedule->getJobCode()] = $schedule;
            $this->processManager->forkExec(
                $schedule->getJobCode(),
                PHP_BINARY,
                array(
                    $this->processManager->getTaskDispatchPath(),
                    '-f',
                    'schedule_' . $schedule->getScheduleId(),
                    '-m',
                    $runConfig->model
                ),
                array($this, 'taskCompleted')
            );

        } catch (Exception $e) {
            $schedule->setStatus($errorStatus)
                ->setMessages($e->__toString());
        }
        $schedule->save();

        return $this;
    }

    public function taskCompleted($name, $pid, $status)
    {
        $schedule = $this->runningTasks[$name];
        unset($this->runningTasks[$name]);

        $errorStatus = Mage_Cron_Model_Schedule::STATUS_SUCCESS;

        if (pcntl_wifsignaled($status) || pcntl_wexitstatus($status) != 0) {
            $errorStatus = Mage_Cron_Model_Schedule::STATUS_ERROR;
            $schedule->setMessages(
                Mage::helper('cron')->__('Task failed with exit code: %d', $status)
            );
        }

        $schedule
            ->setStatus($errorStatus)
            ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
            ->save();

        return $this;
    }
}

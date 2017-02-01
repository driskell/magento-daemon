<?php

require_once 'abstract.php';

class Driskell_Shell_TaskDispatch extends Mage_Shell_Abstract
{
    public function __construct()
    {
        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';
        $this->_parseArgs();

        if (!isset($this->_args['f'])) {
            // Supervisor is starting, prevent Magento from initialising
            return;
        }

        $this->_includeMage = false;
        Mage::app($this->_appCode, $this->_appType);
        parent::__construct();
    }

    public function run()
    {
        if (isset($this->_args['f'])) {
            $dispatcher = Mage::getModel('driskell_taskdispatch/dispatcher');
            if (is_string($this->_args['f'])) {
                $dispatcher->runTask($this->_args);
            } else {
                $dispatcher->runDispatcher();
            }
        } else {
            $manager = new Driskell_TaskDispatch_Model_Processmanager();
            $isReloading = isset($this->_args['-r']);
            $manager->runSupervisor($isReloading);
        }

        return $this;
    }
}

$shell = new Driskell_Shell_TaskDispatch();
$shell->run();

?>

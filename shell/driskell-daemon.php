<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */
require_once 'abstract.php';

/**
 * Shell entry point
 */
class Driskell_Shell_Daemon extends Mage_Shell_Abstract
{
    /**
     * Constructor
     * Prevent Magento initialisation but we still need to bootstrap autoloader by requiring
     */
    public function __construct()
    {
        // Include ourself and flag no include (the default include also initialises)
        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';
        $this->_includeMage = false;
        parent::__construct();
    }

    /**
     * Runner
     *
     * @return void
     */
    public function run()
    {
        if (isset($this->_args['f'])) {
            $this->startFollower();
            return;
        }

        // Start the supervisor without any Magento initialisation
        // All we do is monitor the child, restarting when needed
        // This should reduce singificantly any risk of failure
        $supervisor = new Driskell_Daemon_Model_Supervisor();
        $supervisor->run();
    }

    /**
     * Initialise Magento - we're running a task or starting up the organiser
     * Perform same initialisation as cron.php
     *
     * @return void
     */
    private function startFollower()
    {
        // Only for urls
        // Don't remove this
        $_SERVER['SCRIPT_NAME'] = str_replace(basename(__FILE__), 'index.php', $_SERVER['SCRIPT_NAME']);
        $_SERVER['SCRIPT_FILENAME'] = str_replace(basename(__FILE__), 'index.php', $_SERVER['SCRIPT_FILENAME']);

        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';
        Mage::app('admin')->setUseSessionInUrl(false);
        umask(0);

        // We're running the main organiser or a task so pass on control
        $dispatcher = Mage::getModel('driskell_daemon/dispatcher');
        if (is_string($this->_args['f'])) {
            $dispatcher->runProcess($this->_args);
        } else {
            $dispatcher->runDispatcher();
        }
    }
}

$shell = new Driskell_Shell_Daemon();
$shell->run();

?>

<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */

/**
 * Configuration DAO
 */
class Driskell_Daemon_Model_Config
{
    // We use configuration for this because cache could be cleared any moment
    // So we need a persistent way to track what job the forked default runner is
    // running at any one time so that if it fails we can flag the correct job
    // as failed
    const XML_PATH_ACTIVE_DEFAULT_JOB = 'driskell_daemon/active_default_job';

    const XML_PATH_PARALLEL_JOBS = 'driskell_daemon/general/parallel_jobs';

    /**
     * Return true if job code is a parallel job
     *
     * @param string $jobCode
     * @return boolean
     */
    public function isJobParallel($jobCode)
    {
        $parallelJobs = explode(',', Mage::getStoreConfig(self::XML_PATH_PARALLEL_JOBS));
        return in_array($jobCode, $parallelJobs);
    }

    /**
     * Get the active default job
     *
     * @return int
     */
    public function getActiveDefaultJob()
    {
        return Mage::app()->getStore()->getConfig(self::XML_PATH_ACTIVE_DEFAULT_JOB);
    }

    /**
     * Set the active default job
     *
     * @param int $scheduleId
     * @return void
     */
    public function setActiveDefaultJob($scheduleId)
    {
        Mage::app()->getStore()->setConfig(self::XML_PATH_ACTIVE_DEFAULT_JOB, $scheduleId);
    }

    /**
     * Clear the active default job
     *
     * @return void
     */
    public function clearActiveDefaultJob()
    {
        $this->setActiveDefaultJob(null);
    }
}

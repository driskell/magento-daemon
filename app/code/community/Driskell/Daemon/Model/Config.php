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
    const XML_PATH_PREFIX_ACTIVE_JOB = 'driskell_daemon/active_job/';

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
     * Get the active job for a job type
     *
     * @param string $jobType
     * @return int|null
     */
    public function getActiveJob($jobType)
    {
        $this->validateJobType($jobType);
        // Load directly from database to bypass local config cache
        $config = Mage::getResourceModel('core/config_data_collection')
            ->addFieldToFilter('scope', array('eq' => 'default'))
            ->addFieldToFilter('scope_id', array('eq' => 0))
            ->addFieldToFilter('path', array('eq' => self::XML_PATH_PREFIX_ACTIVE_JOB . $jobType))
            ->getFirstItem();
        $scheduleId = $config ? $config->getValue() : '';
        if ((string)$scheduleId === '') {
            return null;
        }
        return intval($scheduleId);
    }

    /**
     * Set the active job for a job type
     *
     * @param string $jobType
     * @param int $scheduleId
     * @return void
     */
    public function setActiveJob($jobType, $scheduleId)
    {
        $this->validateJobType($jobType);
        Mage::app()->getConfig()->saveConfig(self::XML_PATH_PREFIX_ACTIVE_JOB . $jobType, $scheduleId, 'default', 0);
    }

    /**
     * Clear the active job for a job type
     *
     * @param string $jobType
     * @return void
     */
    public function clearActiveJob($jobType)
    {
        $this->setActiveJob($jobType, '');
    }

    /**
     * Validate job type is valid
     * Throws RuntimeException if it's not valid
     * Otherwise, returns normally
     *
     * @param string $jobType
     * @throws RuntimeException
     * @return void
     */
    private function validateJobType($jobType)
    {
        if (!in_array($jobType, array('default', 'always'))) {
            throw new RuntimeException('Invalid job type');
        }
    }
}

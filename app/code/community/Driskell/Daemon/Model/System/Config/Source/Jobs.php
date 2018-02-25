<?php
/**
 * @copyright See LICENCE.md
 * @package   Driskell_Daemon
 * @author    Jason Woods <devel@jasonwoods.me.uk>
 */

/**
 * Configuration DAO
 */
class Driskell_Daemon_Model_System_Config_Source_Jobs
{
    /**
     * Return options
     *
     * @return array[]
     */
    public function toOptionArray()
    {
        $jobCodeList = array();

        $jobsRoot = Mage::getConfig()->getNode('crontab/jobs');
        if ($jobsRoot instanceof Varien_Simplexml_Element) {
            foreach ($jobsRoot->children() as $jobCode => $jobConfig) {
                $jobCodeList[$jobCode] = 1;
            }
        }

        $defaultJobsRoot = Mage::getConfig()->getNode('default/crontab/jobs');
        if ($defaultJobsRoot instanceof Varien_Simplexml_Element) {
            foreach ($defaultJobsRoot->children() as $jobCode => $jobConfig) {
                $jobCodeList[$jobCode] = 1;
            }
        }

        $options = array();
        ksort($jobCodeList);
        foreach (array_keys($jobCodeList) as $jobCode) {
            $options[] = array(
                'label' => $jobCode,
                'value' => $jobCode
            );
        }
        return $options;
    }
}

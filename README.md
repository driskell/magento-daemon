# Daemon for Magento

Observable job processing for Magento 1 that replaces Magento cron.

## Overview

The usual Magento 1 way to run background jobs is via the Magento cron. This has always been a crude way of running jobs in the background. There are many drawbacks, the major one being any failure of a job is undetectable. Furthermore, knowing if a job is still running from the Magento Admin, even with extensions such as Aoe Scheduler, is not reliable. In fact, such schedulers can begin to mark long running jobs as completed when they haven't.

Daemon provides a first-class system service wrapper for running Magento jobs. It runs permanently in the background, with a supervisor to ensure it restarts on failure, monitoring all running jobs in realtime. As jobs complete the Magento database is updated so extensions that expose schedule activity are entirely accurate at all times. All child processes are also labelled with their running job codes in the system process listing to provide realtime observability of what each running process is doing.

Daemon also provides the ability to mark certain jobs as parallel jobs, that will run independently of all other jobs (which are run serially), thereby allowing long-running complex background jobs to run without impeding the recurrency of critical background jobs.

## Installation

The recommended installation method is to use composer. Alternatively, you can just copy the files into your Magento installation as you would any other extension.

Magento cron should then be disabled and one of the service wrappers from inside the `Services` folder installed and configured. There are service wrappers provided for SysVInit (the `/etc/init.d` folder) and also SystemD, with documentation provided alongside the wrapper.

## Configuration

The following configuration options are currently available in System > Configuration > Driskell > Daemon.

Option | Description
--- | ---
Jobs to run in parallel | Any jobs selected here will not be run in serial with other jobs and have their own containing process. This prevents them from blocking other potentially critical jobs from being held in a queue.

## Monitoring

Running `service driskell-daemon status` or `systemctl status driskell-daemon` will return a nice output along with a process tree showing you exactly what is currently running.

Name | Purpose
--- | ---
supervisor | The main entry point process. It's an empty shell with Magento uninitialised and no connections in order to provide a reliable supervisor that can restart the dispatcher in the event it fails.
dispatcher | The main log house. It manages the scheduling and running of the various different Magento jobs, starting new processes where necessary.
default - XXX - CRONEXPR | The `default` Magento cron, running tasks in serial. The XXX and CRONEXPR will change as it moves from one job to the next and will show the currently running job code and its cron expression respectively. It will say `observers` when running non-standard jobs that run via Magento's event subsystem.
always - XXX - CRONEXPR | Same as `default - XXX - CRONEXPR` but corresponds to the `always` Magento cron instead.
XXX - CRONEXPR | If a job code is configured as parallel, you will see it in a process like this. The XXX and CRONEXPR are the same as with `default - XXX - CRONEXPR` but will not change.

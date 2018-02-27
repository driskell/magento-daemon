# Daemon for Magento

**NOTE: This project is currently experimental and not recommended for Production usage.**

Observable job processing for Magento 1 that replaces Magento cron.

![Output of `service driskell-daemon restart`](intro.jpg)

## Overview

The usual Magento 1 way to run background jobs is via the Magento cron. This has always been a crude way of running jobs in the background. There are many drawbacks, the major one being any failure of a job is undetectable. Furthermore, knowing if a job is still running from the Magento Admin, even with extensions such as Aoe Scheduler, is not reliable. In fact, such schedulers can begin to mark long running jobs as completed or failed when they haven't.

Daemon provides a system service wrapper for running Magento jobs. It runs permanently in the background as the same user as Magento, with its own supervisor to ensure it can recover from failure (database restarts etc.), monitoring all running jobs in realtime. As jobs complete the Magento database is updated so extensions that expose schedule activity are entirely accurate at all times. In the process list child processes are also labelled with their running job codes to provide realtime observability of what is currently running.

Each job is also given a "Blackbox" (its own `error_log` target) to capture every error that occurs whilst running that job, so it can be recorded to the database. This captures not only warnings but notices and... fatal errors! With a little work these could eventually be monitored and messages raised in Magento Admin to the right users when things aren't behaving.

Finally, Daemon also provides the ability to mark certain jobs as parallel jobs, that will run independently of all other jobs, thereby allowing long-running background jobs to run without causing any delays to other jobs.

## Installation

The recommended installation method is to use composer. Alternatively, you can just copy the files into your Magento installation as you would any other extension.

You should then remove Magento's default cron from your crontab and install one of the service wrappers from inside the `services` folder. There are wrappers provided for SysVInit (the `/etc/init.d` folder) and also SystemD, with documentation alongside them.

Add the following to your `composer.json` and then run `composer require driskell/magento-daemon`.

```json
    "repositories": [
        ...
        {
            "type": "vcs",
            "url": "https://github.com/driskell/magento-daemon"
        }
    ]
```

## Configuration

The following configuration options are currently available in System > Configuration > Driskell > Daemon.

Option | Description
--- | ---
Jobs to run in parallel | Any jobs selected here will not be run in serial with other jobs and have their own containing process. This prevents them from blocking other potentially critical jobs from being held in a queue.

## Temporarily Disabling

If you encounter any issues and need to revert to the default cron, all you need to do is stop the service and then start running the Magento cron again.

## Monitoring

Running `service driskell-daemon status` or `systemctl status driskell-daemon` will return a nice output along with a process tree showing you exactly what is currently running.

![Output of `service driskell-daemon status`](screenshot1.jpg)

Name | Purpose
--- | ---
supervisor | The main entry point process. It's an empty shell with Magento uninitialised and no connections in order to provide a reliable supervisor that can restart the dispatcher in the event it fails.
dispatcher | The main log house. It manages the scheduling and running of the various different Magento jobs, starting new processes where necessary.
default - XXX - CRONEXPR | The `default` Magento cron, running tasks in serial. The XXX and CRONEXPR will change as it moves from one job to the next and will show the currently running job code and its cron expression respectively. It will say `observers` when running non-standard jobs that run via Magento's event subsystem.
always - XXX - CRONEXPR | Same as `default - XXX - CRONEXPR` but corresponds to the `always` Magento cron instead.
XXX - CRONEXPR | If a job code is configured as parallel, you will see it in a process like this. The XXX and CRONEXPR are the same as with `default - XXX - CRONEXPR` but will not change.

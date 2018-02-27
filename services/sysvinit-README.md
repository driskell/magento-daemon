# SysVInit

For RedHat 5/6, CentOS 5/6, Amazon Linux and other similar systems.

To install the SysVInit service wrapper, copy the `sysvinit.sh` file to `/etc/init.d/driskell-daemon`.

The last step is to then to copy `sysvinit.conf` to `/etc/sysconfig/driskell-daemon`, and modify the `DAEMON_USER` and `DAEMON_GROUP` within it to set your user and group, and point `SCRIPTPATH` to the `driskell-daemon.php` file inside your Magento installation's `shell` folder.

## Enabling the Service

To allow the service to auto-start on reboot, run `chkconfig driskell-daemon on`.

## Controlling the Service

Now run like any other service!

```
service driskell-daemon start
service driskell-daemon stop
service driskell-daemon reload
service driskell-daemon restart
service driskell-daemon status
```

# SystemD

For RedHat 7, CentOS 7 and other similar systems.

To install the SystemD service wrapper, copy the `systemd.service` file to `/etc/systemd/system/driskell-daemon.service` and run `systemctl daemon-reload`.

Then, to configure the user and group you will need, run `systemctl edit driskell-daemon`, and enter the following.

```
[Service]
User=user
Group=group
```

The last step is to then to copy `systemd.env` to `/etc/sysconfig/driskell-daemon`, and modify the SCRIPTPATH within it to point to the `driskell-daemon.php` file inside your Magento installation's `shell` folder.

## Enabling the Service

To allow the service to auto-start on reboot, run `systemctl enable driskell-daemon`.

## Controlling the Service

Now run like any other SystemD service!

```
systemctl start driskell-daemon
systemctl stop driskell-daemon
systemctl reload driskell-daemon
systemctl restart driskell-daemon
systemctl status driskell-daemon
```

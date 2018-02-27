#!/bin/bash
#
# driskell-daemon Driskell Daemon, a Magento job runner
#
# chkconfig: 2345 90 10
# description: Controls the Driskell Daemon
#
### BEGIN INIT INFO
# Provides:          driskell-daemon
# Required-Start:    $local_fs $remote_fs $syslog
# Required-Stop:     $local_fs $remote_fs $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: Driskell Daemon, a Magento job runner
### END INIT INFO

# source function library
. /etc/rc.d/init.d/functions

NAME=driskell-daemon
DESC="Driskell Daemon"
DAEMON=/usr/bin/php
SCRIPTPATH=/var/www/html/shell/driskell-daemon.php
PID_FILE=/var/run/${NAME}.pid

# Defaults
DAEMON_USER="wwwuser"
DAEMON_GROUP="wwwuser"

# Override defaults from sysconfig
# shellcheck source=/dev/null
[ -f "/etc/sysconfig/${NAME}" ] && . "/etc/sysconfig/${NAME}"

# Colouring book
if [ "$BOOTUP" == "color" ]; then
	SETCOLOR_HEADING="echo -en \\033[1;36m"
else
	SETCOLOR_HEADING=
fi

print_heading_n()
{
	[ "$BOOTUP" == "color" ] && $SETCOLOR_HEADING
	echo -n "$1"
	[ "$BOOTUP" == "color" ] && $SETCOLOR_NORMAL
}

print_heading()
{
	print_heading_n "$1"
	echo
}

do_start()
{
	print_heading_n "Starting ${DESC}: "
	status -p $PID_FILE $DAEMON &>/dev/null
	RC=$?
	if [ $RC -eq 0 ]; then
		success
	else
		if [ "${DAEMON_USER}:${DAEMON_GROUP}" = "root:root" ]; then
			nohup "$DAEMON" "$SCRIPTPATH" </dev/null &>/dev/null &
			RC=$?
			echo "$!" > "$PID_FILE"
		else
			# shellcheck disable=SC2086
			nohup runuser -s /bin/bash "$DAEMON_USER" -g "$DAEMON_GROUP" -c "$(printf '%q' "$DAEMON") $(printf '%q' "$SCRIPTPATH") </dev/null &>/dev/null & echo \"\$!\"" 2>/dev/null >"$PID_FILE"
			RC=$?
		fi
		if [ $RC -eq 0 ]; then
			success
		else
			failure
		fi
	fi
	echo
	return $?
}

do_reload() {
	print_heading_n "Reloading ${DESC}: "
	killproc -p $PID_FILE $DAEMON -HUP
	RC=$?
	echo
}

case "$1" in
	start)
		do_start
		RC=$?
	;;
	stop)
		print_heading_n "Stopping ${DESC}: "
		killproc -p $PID_FILE -d 30 $DAEMON
		RC=$?
		echo
	;;
	status)
		print_heading "${DESC} status:"
		status -p $PID_FILE $DAEMON
		RC=$?
		if [ $RC -eq 0 ]; then
			echo
			print_heading "${DESC} process tree:"
			SID=$(cat "$PID_FILE")
			ps fu -s "$SID"
		fi
	;;
	reload)
		do_reload
	;;
	restart)
		$0 stop
		do_start
		RC=$?
	;;
	condrestart|try-restart)
		status -p $PID_FILE $DAEMON
		RC=$?
		if [ $RC -eq 0 ]; then
			$0 restart
			RC=$?
		fi
	;;
	*)
		print_heading_n "Usage: "
		echo "$0 start|stop|status|reload|restart|condrestart|try-restart"
		exit 1
	;;
esac

exit $RC

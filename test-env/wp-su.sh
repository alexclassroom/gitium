#!/bin/sh
# This is a wrapper so that wp-cli can run as the www-data user so that permissions
# remain correct
TERM=xterm sudo -u $DOCKER_USER /bin/wp-cli.phar "$@"
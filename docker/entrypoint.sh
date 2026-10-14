#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
	''|*[!0-9]*)
		echo "PORT must be a number between 1 and 65535." >&2
		exit 1
		;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
	echo "PORT must be a number between 1 and 65535." >&2
	exit 1
fi

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/uploads/posts
chown www-data:www-data /var/www/html/uploads /var/www/html/uploads/posts
chmod 0755 /var/www/html/uploads /var/www/html/uploads/posts

exec "$@"

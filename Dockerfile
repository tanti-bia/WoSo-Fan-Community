FROM php:8.4-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
	&& php -r 'exit(extension_loaded("fileinfo") ? 0 : 1);'

COPY docker/apache-app.conf /etc/apache2/conf-available/woso-app.conf
RUN a2enconf woso-app

COPY woso_app/ /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/woso-entrypoint
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

RUN chmod 0755 /usr/local/bin/woso-entrypoint \
	&& mkdir -p /var/www/html/uploads/posts \
	&& chown -R www-data:www-data /var/www/html/uploads

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/woso-entrypoint"]
CMD ["apache2-foreground"]

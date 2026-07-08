FROM php:8.3-apache

# Serve the public folder, keep lib, cli, config, and data outside the docroot.
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri -e "s!/var/www/html!/app/public!g" /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e "s!/var/www/!/app/!g" /etc/apache2/apache2.conf

COPY . /app

RUN mkdir -p /app/data && chown -R www-data:www-data /app/data

EXPOSE 80

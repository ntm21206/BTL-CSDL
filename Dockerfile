FROM php:8.2-apache
RUN docker-php-ext-install mysqli
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
COPY . /var/www/html/
FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN a2enmod rewrite
WORKDIR /var/www/html
COPY . /var/www/html/
RUN composer require aws/aws-sdk-php phpmailer/phpmailer
RUN chown -R www-data:www-data /var/www/html
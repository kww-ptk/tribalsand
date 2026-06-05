FROM php:8.2-apache

# Install PostgreSQL PDO extension
RUN apt-get update && apt-get install -y libpq-dev postgresql-client libgd-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy project files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite

# Guarantee .htaccess (mod_rewrite) is honored for the document root — required for
# the extensionless URL rewrite. The base image's <Directory> may default to AllowOverride None.
RUN printf '<Directory /var/www/html>\n    Options FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/tribalsand-override.conf \
    && a2enconf tribalsand-override

# Give Apache write access to upload and log directories
RUN mkdir -p /var/www/html/assets/img/rooms /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/assets/img/rooms /var/www/html/logs \
    && chmod -R 775 /var/www/html/assets/img/rooms /var/www/html/logs

FROM php:8.2-apache

# Install dependencies and PHP extensions (PDO MySQL, GD, SSL CA)
RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Apache port dynamically from $PORT or default to 80
RUN sed -i 's/80/${PORT:-80}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Configure AllowOverride All for .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory & copy files
WORKDIR /var/www/html
COPY . /var/www/html

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]


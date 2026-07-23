FROM richarvey/nginx-php-fpm:3.1.6

# Configure the document root for Slim Framework
ENV WEBROOT /var/www/html/public
ENV APP_ENV production

# Copy all backend source files into the container
COPY . /var/www/html

# Replace the default Nginx configuration
# This allows Slim routes such as /api/health to reach index.php
COPY nginx.conf /etc/nginx/sites-available/default.conf

# Remove local development files
RUN rm -rf /var/www/html/vendor /var/www/html/.env

# Install PHP production dependencies
RUN composer install --no-dev --optimize-autoloader

# Render serves the container through port 80
EXPOSE 80
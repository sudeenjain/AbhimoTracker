FROM richarvey/nginx-php-fpm:3.1.6

# Configure the document root for Slim Framework (must serve from public/)
ENV WEBROOT /var/www/html/public
ENV APP_ENV production

# Copy all backend source files into the container
COPY . /var/www/html

# Clean up any local development files
RUN rm -rf /var/www/html/vendor /var/www/html/.env

# Install production dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose port 80 (Render redirects port 80/8080 traffic here automatically)
EXPOSE 80

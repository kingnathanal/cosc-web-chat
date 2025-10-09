# Base image from Docker Hub PHP 8.2 on Apache server
FROM php:8.3-apache


# Update Container, Install Curl and VIM
RUN apt-get update && apt-get install -y \
    curl \
    vim

# Install PDO and MYSQLi drivers into container
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli
RUN docker-php-ext-install pdo pdo_mysql

# Enables .htaccess to rewrite apache settings
RUN a2enmod rewrite

### IGNORE for now...........

## ini file changes
# COPY ./inifile $PHP_INI_DIR/conf.d/
#RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
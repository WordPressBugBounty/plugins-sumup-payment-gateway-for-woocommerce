FROM wordpress:6.7.1-php8.3-apache

# Install packages under Debian
RUN apt-get update && \
    apt-get -y install git

# Install XDebug from source as described here:
# https://xdebug.org/docs/install
# Available branches of XDebug could be seen here:
# https://github.com/xdebug/xdebug/branches
RUN cd /tmp && \
    git clone https://github.com/xdebug/xdebug.git && \
    cd xdebug && \
    git checkout xdebug_3_3 && \
    phpize && \
    ./configure --enable-xdebug && \
    make && \
    make install && \
    rm -rf /tmp/xdebug

# Copy xdebug.ini to /usr/local/etc/php/conf.d/
COPY files-to-copy/ /
RUN chmod -R 777 /var/www/html


RUN docker-php-ext-enable xdebug

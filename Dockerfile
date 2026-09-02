FROM php:8.5-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        procps \
        htop \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pcntl posix sysvmsg sysvsem sysvshm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV PHP_IDE_CONFIG serverName=php-worker-pool

RUN { \
        echo 'zend_extension=xdebug'; \
        echo 'xdebug.mode=debug'; \
        echo 'xdebug.start_with_request=yes'; \
        echo 'xdebug.client_host=host.docker.internal'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.discover_client_host=1'; \
    } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN mkdir -p /root/.config/htop && { \
        echo 'fields=0 48 2 46 47 49 1'; \
        echo 'sort_key=48'; \
        echo 'tree_view=1'; \
        echo 'hide_kernel_threads=1'; \
    } > /root/.config/htop/htoprc

WORKDIR /app

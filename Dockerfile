FROM php:8.5-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        procps \
        htop \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pcntl posix shmop \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV PHP_IDE_CONFIG serverName=php-worker-pool

# start_with_request=trigger, not yes: this project runs a long-lived master
# plus N forked workers plus forked clients, and with "yes" EVERY one of those
# processes tries to reach a debugger that usually isn't listening. That cost
# is invisible until it isn't - it made an 8-worker benchmark take 60s instead
# of 0.9s, purely in process teardown. Debugging is now opt-in per run:
#   XDEBUG_TRIGGER=1 php bin/server.php      (or the IDE's own trigger)
RUN { \
        echo 'zend_extension=xdebug'; \
        echo 'xdebug.mode=debug'; \
        echo 'xdebug.start_with_request=trigger'; \
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

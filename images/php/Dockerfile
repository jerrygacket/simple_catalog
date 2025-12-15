FROM php:8.3-fpm AS builder

ENV PHP_OPENSSL=yes

RUN apt-get update \
    && apt-get install -y \
    libzip-dev

RUN docker-php-ext-install zip \
    	pdo_mysql


FROM php:8.3-fpm

WORKDIR /var/www

COPY --from=builder /usr/local/lib/php/extensions/no-debug-non-zts-20230831/ \
    /usr/local/lib/php/extensions/no-debug-non-zts-20230831/

RUN apt-get update \
    && apt install -y libzip-dev \
    && docker-php-ext-enable zip \
    	pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

CMD ["php-fpm"]

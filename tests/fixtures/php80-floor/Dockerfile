FROM wordpress:6.9-php8.1-apache@sha256:f73396626d2f1b89d4c556917c15f55cfcc6b5f80595205516e490f0f04015e4 AS wordpress
FROM php:8.0.30-apache@sha256:c87b4c94d53b35520467b6d026f38656ab9a6d446fa3a0fbba1bc633125f9ad2

RUN printf '%s\n' \
        'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/20240201T000000Z bullseye main' \
        'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/20240201T000000Z bullseye-security main' \
        > /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 mysqli gd exif zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 0 && function_exists("posix_geteuid") ? 0 : 1);'

COPY --from=wordpress /usr/src/wordpress /usr/src/wordpress
COPY --from=wordpress /usr/local/bin/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN php -r 'require "/usr/src/wordpress/wp-includes/version.php"; exit($wp_version === "6.9" ? 0 : 1);' \
    && chown -R www-data:www-data /usr/src/wordpress

VOLUME /var/www/html
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

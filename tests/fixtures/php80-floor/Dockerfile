ARG PHP80_BASE_IMAGE
FROM ${PHP80_BASE_IMAGE}
ARG PHP80_BASE_IMAGE
ARG PHP80_BINARY_SHA256
ARG WP_CORE_VERSION
ARG WP_CORE_SHA256
COPY scripts/compatibility-download.sh /opt/php80-floor/download.sh
COPY tests/fixtures/php80-floor /opt/php80-floor
RUN set -eu; \
    printf '%s\n' "$PHP80_BASE_IMAGE" | grep -Eq '^(docker.io/library/)?php:8\.0\.30-cli@sha256:[a-f0-9]{64}$'; \
    printf '%s\n' "$PHP80_BINARY_SHA256" | grep -Eq '^[a-f0-9]{64}$'; \
    printf '%s\n' "$WP_CORE_VERSION" | grep -Eq '^6\.9(\.[0-9]+)?$'; \
    printf '%s\n' "$WP_CORE_SHA256" | grep -Eq '^[a-f0-9]{64}$'; \
    printf '%s  /usr/local/bin/php\n' "$PHP80_BINARY_SHA256" | sha256sum --check --strict; \
    test "$(php -r 'echo PHP_VERSION;')" = 8.0.30; \
    docker-php-ext-install mysqli; \
    printf '%s  /usr/local/bin/php\n' "$PHP80_BINARY_SHA256" | sha256sum --check --strict; \
    bash /opt/php80-floor/download.sh \
      "https://wordpress.org/wordpress-${WP_CORE_VERSION}.tar.gz" "$WP_CORE_SHA256" sha256 /tmp/wordpress.tar.gz; \
    mkdir -p /var/www/html; \
    tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /var/www/html; \
    php -r 'require "/var/www/html/wp-includes/version.php"; if ($wp_version !== $argv[1]) { exit(1); }' "$WP_CORE_VERSION"; \
    rm /tmp/wordpress.tar.gz; \
    chmod 755 /opt/php80-floor/entrypoint.sh
ENV PHP80_BINARY_SHA256=${PHP80_BINARY_SHA256}
ENV PHP_CLI_SERVER_WORKERS=4
WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["/opt/php80-floor/entrypoint.sh"]

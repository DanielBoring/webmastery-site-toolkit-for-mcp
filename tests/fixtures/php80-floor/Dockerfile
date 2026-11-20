# Only WordPress files and its Apache startup configuration come from this stage.
FROM wordpress:6.9-php8.1-apache@sha256:f73396626d2f1b89d4c556917c15f55cfcc6b5f80595205516e490f0f04015e4 AS wordpress
FROM debian:bookworm-slim@sha256:734a72b3d6142a71993b4bc580ca01d8ca687f77a2c422d96a961d3376b179bc
ARG PHP_SERIES=8.0

COPY --from=wordpress /usr/src/wordpress /usr/src/wordpress
COPY --from=wordpress /usr/local/bin/docker-entrypoint.sh /usr/local/bin/apache2-foreground /usr/local/bin/
COPY --from=wordpress /etc/apache2/conf-available/docker-php.conf /etc/apache2/conf-available/docker-php.conf

# The Sury package changelogs identify these first revisions containing both
# CVE-2026-109618 and CVE-2026-109619. Check the CLI and mod_php independently.
RUN set -eux; \
    case "$PHP_SERIES" in \
        8.0) fixed='1:8.0.30-25' ;; \
        8.1) fixed='8.1.34-9' ;; \
        *) exit 1 ;; \
    esac; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl; \
    curl -fsSL --retry 3 https://packages.sury.org/debsuryorg-archive-keyring.deb -o /tmp/keyring.deb; \
    echo '7511384559c9ddf1d5ce5f60be429ae9d4e7d01d9480d6f1b7a30c0810cf8b60  /tmp/keyring.deb' | sha256sum -c -; \
    dpkg -i /tmp/keyring.deb; \
    echo 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main' > /etc/apt/sources.list.d/php.list; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends apache2 "libapache2-mod-php${PHP_SERIES}" \
        php${PHP_SERIES}-bcmath php${PHP_SERIES}-cli php${PHP_SERIES}-curl php${PHP_SERIES}-gd php${PHP_SERIES}-intl \
        php${PHP_SERIES}-mbstring php${PHP_SERIES}-mysql php${PHP_SERIES}-opcache php${PHP_SERIES}-xml php${PHP_SERIES}-zip; \
    for package in "php${PHP_SERIES}-cli" "libapache2-mod-php${PHP_SERIES}"; do \
        version="$(dpkg-query -W -f='${Version}' "$package")"; \
        dpkg --compare-versions "$version" ge "$fixed"; \
    done; \
    php -r 'exit(PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION === $argv[1] && function_exists("posix_geteuid") ? 0 : 1);' "$PHP_SERIES"; \
    php -r 'require "/usr/src/wordpress/wp-includes/version.php"; exit($wp_version === "6.9" ? 0 : 1);'; \
    a2enmod rewrite; \
    a2enconf docker-php; \
    apache2ctl -M | grep -F 'php_module (shared)'; \
    rm -f /var/www/html/index.html /tmp/keyring.deb; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
VOLUME /var/www/html
EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

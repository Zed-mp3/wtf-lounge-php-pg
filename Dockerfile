FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Production PHP settings + extra hardening, Apache modules and config.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.use_strict_mode = 1'; \
        echo 'session.use_only_cookies = 1'; \
        echo 'session.sid_length = 48'; \
        echo 'session.sid_bits_per_character = 6'; \
        echo 'allow_url_fopen = Off'; \
        echo 'allow_url_include = Off'; \
        echo 'disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec'; \
    } > "$PHP_INI_DIR/conf.d/zz-hardening.ini" \
    && a2enmod headers rewrite \
    && { \
        echo 'ServerTokens Prod'; \
        echo 'ServerSignature Off'; \
        echo 'TraceEnable Off'; \
        echo '<Directory /var/www/html>'; \
        echo '    AllowOverride All'; \
        echo '    Options -Indexes'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/hardening.conf \
    && a2enconf hardening

COPY . /var/www/html/
# Code is read-only to the web user; nothing in the app writes to disk.
RUN chown -R root:root /var/www/html && chmod -R a=rX /var/www/html

# Render tells the container which port to listen on via $PORT.
CMD ["/bin/sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/\" /etc/apache2/sites-enabled/000-default.conf && apache2-foreground"]

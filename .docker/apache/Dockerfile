# syntax=docker/dockerfile:1

# The front web server. Debian apache2 (matching production's stack) reverse-
# proxies to the php-fpm pools over FastCGI and serves static assets directly.
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends apache2 \
    && a2enmod proxy proxy_fcgi rewrite remoteip headers setenvif \
    && a2dissite 000-default \
    && sed -i 's/^Listen 80$/Listen 3001/' /etc/apache2/ports.conf \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY .docker/apache/xtools.conf /etc/apache2/sites-available/xtools.conf
RUN a2ensite xtools
EXPOSE 3001
# apachectl sources /etc/apache2/envvars (the APACHE_RUN_* vars) before exec.
CMD ["apachectl", "-D", "FOREGROUND"]

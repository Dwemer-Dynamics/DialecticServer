FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor \
    && mkdir -p /opt/dialectic-relay/public /opt/dialectic-relay/lib /var/lib/dialectic-relay \
    && chown www-data:www-data /var/lib/dialectic-relay \
    && chmod 0700 /var/lib/dialectic-relay
COPY relay/index.php relay/health.php /opt/dialectic-relay/public/
COPY lib/multiplayer_relay.php /opt/dialectic-relay/lib/
COPY relay/hosting/nginx.conf /etc/nginx/nginx.conf.template
COPY relay/hosting/supervisord.conf /etc/supervisord.conf
COPY relay/hosting/php.ini /usr/local/etc/php/conf.d/relay.ini
COPY relay/hosting/fpm.conf /usr/local/etc/php-fpm.d/zz-relay.conf
COPY relay/hosting/entrypoint.sh relay/hosting/cleanup.sh /usr/local/bin/
RUN chmod 0755 /usr/local/bin/entrypoint.sh /usr/local/bin/cleanup.sh

ENV PORT=8080 DIALECTIC_RELAY_STORAGE=/var/lib/dialectic-relay
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

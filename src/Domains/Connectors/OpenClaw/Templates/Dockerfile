FROM {{BASE_IMAGE}}

USER root

RUN apt-get update \
 && apt-get install -y --no-install-recommends cron \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /etc/sudoers.d \
 && echo "node ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/node \
 && chmod 0440 /etc/sudoers.d/node

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

{{OTEL_LAYER}}
USER node

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

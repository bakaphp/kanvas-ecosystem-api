# The coding-agent runtime image.
#
# The upstream image is bare Alpine with the opencode binary and nothing else — no git, no toolchain,
# and no provider SDKs, which is why a session silently falls back to opencode's own hosted model when
# you assume otherwise. Everything a coding task needs has to be added here.
#
# Pull-only in production: the OpenClaw path builds its image on the machine with a 900s budget, which
# a per-task container cannot pay. Build this once, push it, and pin the tag.
FROM ghcr.io/anomalyco/opencode:2.0.16

# git is the deliverable; the rest is what a PHP repo's own tests and linters need.
RUN apk add --no-cache \
        bash \
        composer \
        curl \
        git \
        jq \
        nodejs \
        npm \
        openssh-client \
        php84 \
        php84-ctype \
        php84-curl \
        php84-dom \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-openssl \
        php84-phar \
        php84-session \
        php84-simplexml \
        php84-tokenizer \
        php84-xml \
        php84-xmlwriter \
        php84-zip \
    && ln -sf /usr/bin/php84 /usr/local/bin/php

# Non-root by default: this container runs code we did not write. Existing agent containers run root
# and sit in the host docker group; a coding container must not inherit that.
RUN addgroup -g 1500 kanvasrun \
    && adduser -D -u 1500 -G kanvasrun -h /home/kanvasrun kanvasrun \
    && mkdir -p /home/kanvasrun/.config/opencode /home/kanvasrun/.local/share/opencode /workspace \
    && chown -R kanvasrun:kanvasrun /home/kanvasrun /workspace

# No @ai-sdk/* is installed here on purpose: opencode ships its own provider implementations.
#
# "ModelUnavailableError" is not a provider-packaging problem — it means the workspace is not a git
# repository, which is where opencode looks for the project config. The real packaging trap is that the
# provider it auto-detects from OPENAI_API_KEY sends its request with no Authorization header, a 401
# that reads like a bad key. Declare the provider explicitly instead.

COPY entrypoint.sh /usr/local/bin/kanvas-opencode-entrypoint
RUN chmod +x /usr/local/bin/kanvas-opencode-entrypoint

USER kanvasrun

ENV HOME=/home/kanvasrun
WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/kanvas-opencode-entrypoint"]
# v2 validates --log-level against a lowercase set and exits on "ERROR".
CMD ["serve", "--hostname", "0.0.0.0", "--port", "4096", "--print-logs", "--log-level", "error"]

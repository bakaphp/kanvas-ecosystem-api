FROM ghcr.io/phioranex/openclaw-docker:latest

USER root

RUN mkdir -p /etc/sudoers.d \
 && echo "node ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/node \
 && chmod 0440 /etc/sudoers.d/node

USER node

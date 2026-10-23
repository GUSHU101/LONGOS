FROM node:22.22.0-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && echo "postfix postfix/mailname string localhost" | debconf-set-selections \
    && echo "postfix postfix/main_mailer_type string 'Internet Site'" | debconf-set-selections \
    && apt-get install -y --no-install-recommends postfix opendkim opendkim-tools ca-certificates gosu procps \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY docker/mail-relay/package.json docker/mail-relay/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund

COPY docker/mail-relay/src ./src
COPY docker/mail-relay/entrypoint.sh ./entrypoint.sh
RUN chmod 0755 /app/entrypoint.sh

EXPOSE 25 3000
ENTRYPOINT ["/app/entrypoint.sh"]

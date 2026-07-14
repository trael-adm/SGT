FROM php:8.3-cli

# Instala extensões PHP necessárias
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .

# Garante que o diretório de uploads existe e é gravável
RUN mkdir -p uploads && chmod -R 755 uploads

# Railway injeta a variável PORT — usa 80 como fallback local
CMD php -S 0.0.0.0:${PORT:-80} -t .

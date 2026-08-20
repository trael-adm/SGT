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
RUN mkdir -p uploads storage/cache && chmod -R 777 uploads storage

# Configura limite de memória do PHP para processar planilhas grandes
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Railway injeta a variável PORT — usa 80 como fallback local
CMD php -d memory_limit=512M -S 0.0.0.0:${PORT:-80} -t .

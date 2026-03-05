FROM php:8.2-apache-bullseye

# Habilitar mod_rewrite e instalar dependências
RUN a2enmod rewrite \
    && apt-get update && apt-get install -y \
        libpq-dev \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# Instalar composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Copiar arquivos do composer e instalar dependências (aproveita o cache)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copiar o restante da aplicação
COPY . .

# Executar scripts do composer, se houver
RUN composer dump-autoload --optimize && composer run-script post-install-cmd --no-dev || true

# Definir permissões
RUN chown -R www-data:www-data /var/www/html

# Expor a porta e iniciar o servidor
EXPOSE 80
CMD ["apache2-foreground"]
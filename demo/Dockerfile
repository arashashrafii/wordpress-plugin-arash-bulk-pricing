FROM wordpress:php8.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl tar; \
    curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz -o /tmp/ioncube_loaders.tar.gz; \
    mkdir -p /tmp/ioncube; \
    tar -xzf /tmp/ioncube_loaders.tar.gz -C /tmp/ioncube; \
    extension_dir="$(php-config --extension-dir)"; \
    install -m 0644 /tmp/ioncube/ioncube/ioncube_loader_lin_8.2.so "$extension_dir/ioncube_loader_lin_8.2.so"; \
    printf 'zend_extension=%s/ioncube_loader_lin_8.2.so\n' "$extension_dir" > /usr/local/etc/php/conf.d/00-ioncube.ini; \
    php -v | grep -qi 'ionCube PHP Loader'; \
    rm -rf /tmp/ioncube /tmp/ioncube_loaders.tar.gz /var/lib/apt/lists/*

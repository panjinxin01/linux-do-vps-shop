FROM php:8.2-apache

# 安装必要的 PHP 扩展
RUN docker-php-ext-install pdo pdo_mysql mysqli

# 启用 Apache rewrite 模块
RUN a2enmod rewrite

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件
COPY . /var/www/html/

# 设置权限
RUN chown -R www-data:www-data /var/www/html

# 暴露端口
EXPOSE 80

FROM php:7.4-apache

RUN apt-get update && apt-get install -yq \
		exiftool \
		ffmpeg \
		libvips-tools \
        jpegoptim \
        git 


RUN apt-get install -y ca-certificates \
    && update-ca-certificates

RUN pwd
RUN mkdir /var/www/html/fastback
RUN mkdir -p /cache/cachedir
# RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Added to get make_thumbs to run
RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install \
    pcntl


RUN git clone https://github.com/stuporglue/fastback.git /var/www/html/fastback
WORKDIR /var/www/html/
RUN mv fastback/direct_file_access.htaccess .htaccess
RUN ls -al fastback
RUN rm -rf fastback/.git fastback/.gitignore
RUN mv fastback/index.php .
RUN sed -i "s|photobase\ =\ __DIR__|photobase\ =\ '/photos'|" index.php
RUN sed -i "s|\/\/\ \$fb->csvfile|\$fb->csvfile|" index.php
RUN sed -i "s|\/\/\ \$fb->sqlitefile|\$fb->sqlitefile|" index.php
RUN sed -i "s|\/\/\ \$fb->filecache|\$fb->filecache|" index.php
RUN sed -i "s|mount/fastdisk|cache|" index.php
RUN sed -i "$(grep -n 'run()' index.php | cut -f1 -d':') i \$fb\-\>debug\ =\ true\;" index.php

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
RUN chown -R www-data:www-data /var/www 
RUN chown -R www-data:www-data /cache
RUN a2enmod rewrite

EXPOSE 80

FROM thebiggive/php:7.4

# Artifacts are immutable so *never* bother re-checking files - this makes opcache.revalidate_freq irrelevant
# See https://www.scalingphpbook.com/blog/2014/02/14/best-zend-opcache-settings.html
RUN echo 'opcache.validate_timestamps = 0' >> /usr/local/etc/php/conf.d/opcache-ecs.ini

# Install the AWS CLI - needed to load in secrets safely from S3. See https://aws.amazon.com/blogs/security/how-to-manage-secrets-for-amazon-ec2-container-service-based-applications-by-using-amazon-s3-and-docker/
RUN apt-get update -qq && apt-get install -y python unzip && \
    cd /tmp && \
    curl "https://s3.amazonaws.com/aws-cli/awscli-bundle.zip" -o "awscli-bundle.zip" && \
    unzip awscli-bundle.zip && \
    ./awscli-bundle/install -i /usr/local/aws -b /usr/local/bin/aws && \
    rm awscli-bundle.zip && rm -rf awscli-bundle && \
    rm -rf /var/lib/apt/lists/* /var/cache/apk/*

ADD . /var/www/html

# Ensure Apache can run as www-data and still write to these when the Docker build creates them as root.
RUN chmod 777 /var/www/html/var/cache /var/www/html/var/doctrine /var/www/html/var/doctrine/proxies

RUN composer install --no-interaction --quiet --optimize-autoloader --no-dev

EXPOSE 80

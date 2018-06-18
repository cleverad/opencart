## Init MySQL

 * sudo apt-get update
 * sudo apt-get install mysql-server
 * sudo apt-get install php-mysql
 * mysql_secure_installation

## Init Nginx

 * sudo cp ./app_init/nginx.conf /etc/nginx/conf.d/korpacha.conf
 * sudo service nginx restart
 
## Install App
 * cp upload/config-dist.php upload/config.php
 * cp upload/admin/config-dist.php upload/admin/config.php

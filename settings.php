<?php
define('C_REST_CLIENT_ID','b54f87b2-c58b-42e6-8b15-af1407b41ae5');//Application ID
define('C_REST_CLIENT_SECRET','nH3T2Bmq6tDaSha2ONRpTYVzbKvaXV1tAHnvpcNkAdEEv55uezBnAgamS3j8VPnn');//Application key
define('C_REST_REDIRECT_URI','https://ecomtest.ru/install.php');//Application key

define('C_REST_MYSQL_DBNAME','ecomkassa');//
define('C_REST_MYSQL_USERNAME','ecomkassa');//
define('C_REST_MYSQL_PASSWORD','-');//

define('C_REST_MAIN_DOMAIN','ecomtest.ru');//


try {
    $db = new PDO('mysql:host=localhost;dbname='.C_REST_MYSQL_DBNAME, C_REST_MYSQL_USERNAME, C_REST_MYSQL_PASSWORD);
} catch (PDOException $e) {
    die();
}



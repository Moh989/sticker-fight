<?php

$root = trim(file_get_contents(__DIR__.'/../.runtime/mysql-root-password'));
$db = new mysqli('localhost', 'root', $root, '', 0, __DIR__.'/../.runtime/mysql.sock');
$db->query('CREATE DATABASE IF NOT EXISTS sticker_fight_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->query("GRANT ALL PRIVILEGES ON sticker_fight_qa.* TO 'sticker_fight'@'127.0.0.1'");
$env = file_get_contents(__DIR__.'/../.env');
$env = str_replace(['DB_DATABASE=sticker_fight', 'APP_URL=http://127.0.0.1:8000', 'APP_ENV=local'], ['DB_DATABASE=sticker_fight_qa', 'APP_URL=http://127.0.0.1:8001', 'APP_ENV=qa'], $env);
file_put_contents(__DIR__.'/../.env.qa', $env);
chmod(__DIR__.'/../.env.qa', 0600);
echo "Isolated QA database created.\n";

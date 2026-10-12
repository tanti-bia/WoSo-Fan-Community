<?php
declare(strict_types=1);

function database_connection(): PDO
{
	$host = getenv('WOSO_DB_HOST') ?: 'localhost';
	$port = getenv('WOSO_DB_PORT') ?: '3306';
	$name = getenv('WOSO_DB_NAME') ?: 'woso_community';
	$user = getenv('WOSO_DB_USER') ?: 'root';
	$password = getenv('WOSO_DB_PASSWORD');

	$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

	return new PDO($dsn, $user, $password === false ? '' : $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
}

<?php
session_start();
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
define('BASE_URL', "{$protocol}://{$host}/lewa"); 

session_unset();     
session_destroy();  
header("Location: " . BASE_URL . "/user_login.php?logout=success");
exit();
?>
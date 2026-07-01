<?php
require __DIR__ . '/../vendor/autoload.php';
use Garanti\Auth\Session;
Session::logout();
header('Location: login.php');

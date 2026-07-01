<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';
use Garanti\Auth\Session;
Session::requireLogin();

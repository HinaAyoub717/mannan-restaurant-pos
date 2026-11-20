<?php
require 'db.php';
$_SESSION = [];
session_destroy();
respond(['success' => true]);

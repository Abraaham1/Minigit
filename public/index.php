<?php
require_once __DIR__ . '/../includes/auth.php';

redirect(is_logged_in() ? 'home.php' : 'login.php');

<?php
require_once 'config.php';
session_destroy();
redirect(SITE_URL . '/login.php');
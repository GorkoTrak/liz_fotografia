<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'liz-fotoestudio');

define('SITE_URL', 'http://localhost/liz-fotoestudio');
define('SITE_NAME', 'Lizdy Pineda Fotoestudio');

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Database {

    private static $instance = null;
    private $conn;

    private function __construct() {

        $this->conn = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );

        if ($this->conn->connect_error) {
            die("Error de conexión: " . $this->conn->connect_error);
        }

        $this->conn->set_charset("utf8mb4");
    }

    public static function getInstance() {

        if (self::$instance === null) {
            self::$instance = new Database();
        }

        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    private function __clone() {}
}

function getDB() {
    return Database::getInstance()->getConnection();
}

function estaLogueado() {
    return isset($_SESSION['usuario_id']);
}

function esAdmin() {
    return ($_SESSION['usuario_rol'] ?? '') === 'admin';
}

function requerirLogin() {

    if (!estaLogueado()) {
        redirect(SITE_URL . '/login.php');
    }
}

function requerirAdmin() {

    requerirLogin();

    if (!esAdmin()) {
        redirect(SITE_URL . '/index.php');
    }
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function sanitize($data) {
    return htmlspecialchars(
        stripslashes(trim($data)),
        ENT_QUOTES,
        'UTF-8'
    );
}

function sanitizeInt($data) {
    return intval($data);
}

function formatoPeso($v) {
    return '$' . number_format($v, 0, ',', '.');
}

function formatoFecha($f) {

    if (!$f) {
        return '—';
    }

    $dt = new DateTime($f);

    $m = [
        'Ene','Feb','Mar','Abr','May','Jun',
        'Jul','Ago','Sep','Oct','Nov','Dic'
    ];

    return $dt->format('d') . ' ' .
           $m[$dt->format('n') - 1] . ' ' .
           $dt->format('Y');
}

function formatoHora($h) {

    if (!$h) {
        return '—';
    }

    return date('g:i A', strtotime($h));
}
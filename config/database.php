<?php
class Database {
    private $host;
    private $db_name;
    private $user;
    private $password;
    private $pdo;

    public function __construct() {
        // Get database credentials from environment variables (Render provides these)
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db_name = getenv('DB_NAME') ?: 'kapasigan_db';
        $this->user = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASSWORD') ?: '';

        $this->connect();
    }

    private function connect() {
        try {
            $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->db_name . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, $this->user, $this->password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die('Database Connection Error: ' . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}
?>

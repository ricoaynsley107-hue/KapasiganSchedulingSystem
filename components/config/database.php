<?php
class Database {
    private $host = "localhost";
    private $db_name = "barangay_kapasigan";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Database connection successful");
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>

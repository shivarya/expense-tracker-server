<?php
class Database
{
  private static $instance = null;
  private PDO $connection;

  private function __construct()
  {
    $this->connect();
  }

  private function connect(): void
  {
    try {
      $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
      $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ];
      $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
      throw new Exception("Database connection failed: " . $e->getMessage());
    }
  }

  private function reconnect(): void
  {
    $this->connect();
  }

  public function forceReconnect(): void
  {
    $this->reconnect();
  }

  private function isGoneAway(PDOException $e): bool
  {
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'server has gone away') || str_contains($message, 'lost connection')) {
      return true;
    }

    $errorInfo = $e->errorInfo;
    if (is_array($errorInfo) && isset($errorInfo[1]) && (int)$errorInfo[1] === 2006) {
      return true;
    }

    return false;
  }

  public static function getInstance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function getConnection()
  {
    return $this->connection;
  }

  public function query($sql, $params = [])
  {
    $attempt = 0;
    while ($attempt < 2) {
      try {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
      } catch (PDOException $e) {
        $attempt++;
        if ($attempt < 2 && $this->isGoneAway($e)) {
          error_log('[DB] Connection lost. Reconnecting and retrying query once.');
          $this->reconnect();
          continue;
        }
        throw new Exception("Query failed: " . $e->getMessage());
      }
    }

    throw new Exception('Query failed: retry limit reached');
  }

  public function fetchAll($sql, $params = [])
  {
    $stmt = $this->query($sql, $params);
    return $stmt->fetchAll();
  }

  public function fetchOne($sql, $params = [])
  {
    $stmt = $this->query($sql, $params);
    return $stmt->fetch();
  }

  public function insert($sql, $params = [])
  {
    $this->query($sql, $params);
    return $this->connection->lastInsertId();
  }

  public function execute($sql, $params = [])
  {
    $stmt = $this->query($sql, $params);
    return $stmt->rowCount();
  }

  public function beginTransaction()
  {
    return $this->connection->beginTransaction();
  }

  public function commit()
  {
    return $this->connection->commit();
  }

  public function rollback()
  {
    return $this->connection->rollBack();
  }
}

// Helper function to get database instance
function getDB()
{
  return Database::getInstance();
}


<?php

class AdminActivityRepository
{
    private \mysqli $conn;

    
    private array $where_conditions = ['1=1'];

    
    private array $params = [];

    private string $types = '';

    private string $where_sql = '1=1';

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    

    public function buildFilter(string $action_filter, string $search_q, int $days): void
    {
        $this->where_conditions = ['1=1'];
        $this->params           = [];
        $this->types            = '';

        if ($action_filter !== '') {
            $this->where_conditions[] = 'al.action = ?';
            $this->params[] = $action_filter;
            $this->types .= 's';
        }

        if ($search_q !== '') {
            $this->where_conditions[] = '(u.username LIKE ? OR al.ip_address LIKE ?)';
            $search_like = "%{$search_q}%";
            $this->params[] = $search_like;
            $this->params[] = $search_like;
            $this->types .= 'ss';
        }

        $this->where_conditions[] = 'al.created_at >= NOW() - INTERVAL ? DAY';
        $this->params[] = $days;
        $this->types .= 'i';

        $this->where_sql = implode(' AND ', $this->where_conditions);
    }

    
    public function clearOlderThan(int $days): int
    {
        $stmt = $this->conn->prepare('DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL ? DAY');
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $next_id = 1;
        $max_res = $this->conn->query('SELECT MAX(id) AS max_id FROM activity_log');
        if ($max_res) {
            $max_row = $max_res->fetch_assoc();
            $next_id = $max_row['max_id'] ? (int)$max_row['max_id'] + 1 : 1;
            $this->conn->query('ALTER TABLE activity_log AUTO_INCREMENT = ' . (int)$next_id);
        }
        return $deleted;
    }

    
    public function clearAll(): bool
    {
        return $this->conn->query('TRUNCATE TABLE activity_log') !== false;
    }

    
    public function countFiltered(): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE {$this->where_sql}"
        );
        if (!empty($this->params)) {
            $stmt->bind_param($this->types, ...$this->params);
        }
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    
    public function fetchPage(int $limit, int $offset)
    {
        $stmt = $this->conn->prepare(
            "SELECT al.*, u.username
             FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $all_params = array_merge($this->params, [$limit, $offset]);
        $all_types  = $this->types . 'ii';
        $stmt->bind_param($all_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    
    public function fetchAll(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT al.*, u.username
             FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY al.created_at DESC"
        );
        if (!empty($this->params)) {
            $stmt->bind_param($this->types, ...$this->params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    
    public function getDistinctActions(): array
    {
        $actions = [];
        $res = $this->conn->query('SELECT DISTINCT action FROM activity_log ORDER BY action ASC');
        if ($res) {
            while ($a = $res->fetch_assoc()) {
                $actions[] = $a['action'];
            }
        }
        return $actions;
    }

    
    public function getWeeklyStats(): array
    {
        $res = $this->conn->query(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS unique_users
             FROM activity_log WHERE created_at >= NOW() - INTERVAL 7 DAY"
        );
        return $res ? $res->fetch_assoc() : ['total' => 0, 'unique_users' => 0];
    }
}

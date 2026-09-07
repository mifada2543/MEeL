<?php

class AdminUploadQueueRepository
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

    public function buildFilter(string $status, string $search_q, int $days): void
    {
        $this->where_conditions = ['1=1'];
        $this->params           = [];
        $this->types            = '';

        if ($status !== '' && in_array($status, ['processing', 'completed', 'failed'], true)) {
            $this->where_conditions[] = 'uq.status = ?';
            $this->params[] = $status;
            $this->types .= 's';
        }

        if ($search_q !== '') {
            $this->where_conditions[] = '(u.username LIKE ? OR uq.url LIKE ?)';
            $search_like = "%{$search_q}%";
            $this->params[] = $search_like;
            $this->params[] = $search_like;
            $this->types .= 'ss';
        }

        $this->where_conditions[] = 'uq.created_at >= NOW() - INTERVAL ? DAY';
        $this->params[] = $days;
        $this->types .= 'i';

        $this->where_sql = implode(' AND ', $this->where_conditions);
    }

    public function countFiltered(): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM upload_queue uq LEFT JOIN users u ON uq.user_id = u.id WHERE {$this->where_sql}"
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
            "SELECT uq.*, u.username
             FROM upload_queue uq
             LEFT JOIN users u ON uq.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY uq.created_at DESC
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
            "SELECT uq.*, u.username
             FROM upload_queue uq
             LEFT JOIN users u ON uq.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY uq.created_at DESC"
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

    public function getStats(): array
    {
        $res = $this->conn->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'completed') AS completed_count,
                SUM(status = 'failed') AS failed_count,
                SUM(status = 'processing') AS processing_count
             FROM upload_queue"
        );
        return $res ? $res->fetch_assoc() : [
            'total' => 0,
            'completed_count' => 0,
            'failed_count' => 0,
            'processing_count' => 0,
        ];
    }

    public function clearOlderThan(int $days): int
    {
        $stmt = $this->conn->prepare('DELETE FROM upload_queue WHERE created_at < NOW() - INTERVAL ? DAY');
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $next_id = 1;
        $max_res = $this->conn->query('SELECT MAX(id) AS max_id FROM upload_queue');
        if ($max_res) {
            $max_row = $max_res->fetch_assoc();
            $next_id = $max_row['max_id'] ? (int)$max_row['max_id'] + 1 : 1;
            $this->conn->query('ALTER TABLE upload_queue AUTO_INCREMENT = ' . (int)$next_id);
        }
        return $deleted;
    }
}

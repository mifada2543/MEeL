<?php

class MediaAdminRepository
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Ambil record media (video atau music) + data uploader.
     *
     * @param string $media_type 'video' | 'music'
     * @return array|null
     */
    public function getMedia(string $media_type, int $id): ?array
    {
        $table = $media_type === 'music' ? 'music' : 'video';
        $alias = $media_type === 'music' ? 'm' : 'v';

        $stmt = $this->conn->prepare(
            "SELECT {$alias}.*, u.username AS uploader, u.profile_picture AS uploader_pfp
             FROM {$table} {$alias}
             JOIN users u ON {$alias}.user_id = u.id
             WHERE {$alias}.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Update record video. */
    public function updateVideo(int $id, string $title, string $description, string $thumbnail, string $search_metadata): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE video SET title = ?, description = ?, thumbnail = ?, search_metadata = ? WHERE id = ?'
        );
        $stmt->bind_param('ssssi', $title, $description, $thumbnail, $search_metadata, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Update record music. */
    public function updateMusic(int $id, string $title, string $artist, string $album, string $description, string $thumbnail, string $search_metadata): bool
    {
        $stmt = $this->conn->prepare(
            'UPDATE music SET title = ?, artist = ?, album = ?, description = ?, thumbnail = ?, search_metadata = ? WHERE id = ?'
        );
        $stmt->bind_param('ssssssi', $title, $artist, $album, $description, $thumbnail, $search_metadata, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

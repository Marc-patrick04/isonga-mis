<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'guild_president') {
    die('Unauthorized');
}

$report_id = $_GET['id'] ?? 0;
$is_team = $_GET['team'] ?? 0;

try {
    $table = $is_team ? 'team_report_media' : 'report_media';
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE report_id = ?");
    $stmt->execute([$report_id]);
    $mediaFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($mediaFiles) {
        echo '<div class="media-gallery">';
        
        foreach ($mediaFiles as $media) {
            $fileExtension = strtolower(pathinfo($media['file_name'], PATHINFO_EXTENSION));
            $isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            
            echo '<div class="media-item">';
            
            if ($isImage) {
                echo '<img src="../uploads/reports/' . htmlspecialchars($media['file_path']) . '" 
                          alt="' . htmlspecialchars($media['file_name']) . '">';
            } else {
                echo '<div class="file-icon">
                        <i class="fas fa-file-' . getFileIcon($fileExtension) . '"></i>
                      </div>';
            }
            
            echo '<div class="media-info">
                    <div class="file-name" title="' . htmlspecialchars($media['file_name']) . '">
                        ' . htmlspecialchars($media['file_name']) . '
                    </div>
                    <div style="font-size: 0.7rem; color: var(--dark-gray);">
                        ' . formatFileSize($media['file_size']) . '
                    </div>
                    <button class="btn btn-primary btn-sm" style="margin-top: 0.5rem; width: 100%;" 
                            onclick="downloadMedia(' . $media['id'] . ')">
                        <i class="fas fa-download"></i> Download
                    </button>
                  </div>
                </div>';
        }
        
        echo '</div>';
    } else {
        echo '<p>No attachments found for this report.</p>';
    }
} catch (PDOException $e) {
    echo '<p>Error loading media files.</p>';
}

function getFileIcon($extension) {
    $icons = [
        'pdf' => 'pdf',
        'doc' => 'word', 'docx' => 'word',
        'xls' => 'excel', 'xlsx' => 'excel',
        'ppt' => 'powerpoint', 'pptx' => 'powerpoint',
        'zip' => 'archive', 'rar' => 'archive'
    ];
    
    return $icons[$extension] ?? 'alt';
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>
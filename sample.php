<?php 
$total_images = 0;
// Debug output - remove after testing
if (empty($images)) {
    echo "<!-- Debug: No images found. Total in DB: " . $total_images . " -->";
    // Check if there are any images at all
    try {
        $check = $pdo->query("SELECT COUNT(*) FROM gallery_images");
        $total = $check->fetchColumn();
        echo "<!-- Debug: Total images in database: " . $total . " -->";
        
        $check_status = $pdo->query("SELECT status, COUNT(*) FROM gallery_images GROUP BY status");
        echo "<!-- Debug: Status distribution: " . print_r($check_status->fetchAll(), true) . " -->";
    } catch (Exception $e) {
        echo "<!-- Debug error: " . $e->getMessage() . " -->";
    }
}
?>
<?php
require_once 'config/database.php';

// File name for download
$filename = "committee_members_" . date('Y-m-d_H-i-s') . ".xls";

// Headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Fetch data
$query = "
    SELECT 
        cm.name,
        cm.reg_number,
        cm.role,
        cm.email,
        cm.phone,
        cm.date_of_birth,
        cm.academic_year,
        d.name AS department,
        p.name AS program,
        cm.status,
        cm.created_at
    FROM committee_members cm
    LEFT JOIN departments d ON cm.department_id = d.id
    LEFT JOIN programs p ON cm.program_id = p.id
    ORDER BY cm.created_at DESC
";

$stmt = $pdo->query($query);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Excel table output
echo "<table border='1'>";


// Header row
echo "<tr>
        <th>Full Name</th>
        <th>Registration Number</th>
        <th>Role</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Date of Birth</th>
        <th>Academic Year</th>
        <th>Department</th>
        <th>Program</th>
        <th>Status</th>
        <th>Created At</th>
      </tr>";

// Data rows
foreach ($data as $row) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['reg_number']) . "</td>";
    echo "<td>" . htmlspecialchars($row['role']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
    echo "<td>" . htmlspecialchars($row['date_of_birth']) . "</td>";
    echo "<td>" . htmlspecialchars($row['academic_year']) . "</td>";
    echo "<td>" . htmlspecialchars($row['department']) . "</td>";
    echo "<td>" . htmlspecialchars($row['program']) . "</td>";
    echo "<td>" . htmlspecialchars($row['status']) . "</td>";
    echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
    echo "</tr>";
}

echo "</table>";

exit;
?>
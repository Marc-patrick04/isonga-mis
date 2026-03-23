<?php
function getDashboardUrl($role) {
    $rolePaths = [
        'admin' => '../admin/dashboard.php',
        'guild_president' => '../guild_president/dashboard.php',
        'vice_guild_academic' => '../vice_guild_academic/dashboard.php',
        'vice_guild_finance' => '../vice_guild_finance/dashboard.php',
        'general_secretary' => '../general_secretary/dashboard.php',
        'minister_sports' => '../minister_sports/dashboard.php',
        'minister_environment' => '../minister_environment/dashboard.php',
        'minister_public_relations' => '../minister_public_relations/dashboard.php',
        'minister_health' => '../minister_health/dashboard.php',
        'minister_culture' => '../minister_culture/dashboard.php',
        'minister_gender' => '../minister_gender/dashboard.php',
        'president_representative_board' => '../president_representative_board/dashboard.php',
        'vice_president_representative_board' => '../vice_president_representative_board/dashboard.php',
        'secretary_representative_board' => '../secretary_representative_board/dashboard.php',
        'president_arbitration' => '../president_arbitration/dashboard.php',
        'vice_president_arbitration' => '../vice_president_arbitration/dashboard.php',
        'advisor_arbitration' => '../advisor_arbitration/dashboard.php',
        'secretary_arbitration' => '../secretary_arbitration/dashboard.php'
    ];
    
    return $rolePaths[$role] ?? '../dashboard.php';
}
?>
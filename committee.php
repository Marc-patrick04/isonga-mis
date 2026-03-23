<?php
session_start();
require_once 'config/database.php';

// Get all committee members from database with proper joins
try {
    $stmt = $pdo->query("
        SELECT 
            cm.*,
            d.name as department_name,
            p.name as program_name,
            u.avatar_url as user_avatar
        FROM committee_members cm
        LEFT JOIN departments d ON cm.department_id = d.id
        LEFT JOIN programs p ON cm.program_id = p.id
        LEFT JOIN users u ON cm.user_id = u.id
        WHERE cm.status = 'active'
        ORDER BY cm.role_order ASC, cm.name ASC
    ");
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $committee_members = [];
}

// Define committee positions with descriptions for role grouping
$committee_positions = [
    'guild_president' => [
        'title' => 'Guild President',
        'description' => 'Overall system overseer and chief representative of student body. Leads the executive committee and represents students at college level.',
        'icon' => 'fa-crown',
        'color' => '#dc2626'
    ],
    'vice_guild_academic' => [
        'title' => 'Vice Guild President - Academic & Innovation',
        'description' => 'Manages academic innovation and follows up on academic performance. Coordinates with academic departments on student academic matters.',
        'icon' => 'fa-graduation-cap',
        'color' => '#059669'
    ],
    'vice_guild_finance' => [
        'title' => 'Vice Guild President - Administration & Finance',
        'description' => 'Manages financial matters and student contributions. Oversees budget allocation and financial reporting for student activities.',
        'icon' => 'fa-chart-line',
        'color' => '#7c3aed'
    ],
    'general_secretary' => [
        'title' => 'General Secretary',
        'description' => 'Chief document keeper and records manager. Maintains official records and coordinates communication between different committees.',
        'icon' => 'fa-file-alt',
        'color' => '#0ea5e9'
    ],
    'minister_sports' => [
        'title' => 'Minister of Sports & Entertainment',
        'description' => 'Organizes sports activities and entertainment events. Promotes physical wellness and recreational activities among students.',
        'icon' => 'fa-futbol',
        'color' => '#f59e0b'
    ],
    'minister_environment' => [
        'title' => 'Minister of Environment & Protection',
        'description' => 'Oversees campus environment and protection initiatives. Promotes sustainability and environmental awareness campaigns.',
        'icon' => 'fa-leaf',
        'color' => '#10b981'
    ],
    'minister_public_relations' => [
        'title' => 'Minister of Public Relations & Association',
        'description' => 'Manages media relations and public communications. Builds partnerships with external organizations and stakeholders.',
        'icon' => 'fa-bullhorn',
        'color' => '#8b5cf6'
    ],
    'minister_health' => [
        'title' => 'Minister of Health & Social Affairs',
        'description' => 'Coordinates health awareness programs and social support services. Works with college health services to address student health concerns.',
        'icon' => 'fa-heartbeat',
        'color' => '#ef4444'
    ],
    'minister_culture' => [
        'title' => 'Minister of Culture & Civic Education',
        'description' => 'Promotes cultural activities and civic education. Organizes cultural festivals and citizenship awareness programs.',
        'icon' => 'fa-monument',
        'color' => '#f97316'
    ],
    'minister_gender' => [
        'title' => 'Minister of Gender & Protocol',
        'description' => 'Addresses gender-related issues and ensures proper protocol. Promotes gender equality and handles discrimination cases.',
        'icon' => 'fa-venus-mars',
        'color' => '#ec4899'
    ],
    'president_representative_board' => [
        'title' => 'President of Representative Board',
        'description' => 'Leads the class representatives board. Coordinates with class reps to gather student feedback and concerns.',
        'icon' => 'fa-users',
        'color' => '#6366f1'
    ],
    'vice_president_representative_board' => [
        'title' => 'Vice President of Representative Board',
        'description' => 'Supports the president in board activities. Manages class representative meetings and follow-ups.',
        'icon' => 'fa-user-friends',
        'color' => '#3b82f6'
    ],
    'secretary_representative_board' => [
        'title' => 'Secretary of Representative Board',
        'description' => 'Maintains records for the representative board. Documents meeting minutes and action points from class rep sessions.',
        'icon' => 'fa-clipboard-list',
        'color' => '#06b6d4'
    ],
    'president_arbitration' => [
        'title' => 'President of Arbitration Committee',
        'description' => 'Leads conflict resolution and disciplinary matters. Ensures fair hearing processes for student disputes.',
        'icon' => 'fa-balance-scale',
        'color' => '#64748b'
    ],
    'vice_president_arbitration' => [
        'title' => 'Vice President of Arbitration Committee',
        'description' => 'Assists in arbitration processes and case management. Coordinates mediation sessions between conflicting parties.',
        'icon' => 'fa-handshake',
        'color' => '#475569'
    ],
    'advisor_arbitration' => [
        'title' => 'Advisor of Arbitration Committee',
        'description' => 'Provides guidance on complex arbitration cases. Brings professional expertise to dispute resolution processes.',
        'icon' => 'fa-user-tie',
        'color' => '#334155'
    ],
    'secretary_arbitration' => [
        'title' => 'Secretary of Arbitration Committee',
        'description' => 'Maintains arbitration records and documentation. Ensures proper filing of all arbitration proceedings and outcomes.',
        'icon' => 'fa-file-contract',
        'color' => '#1e293b'
    ]
];

// Group committee members by their roles for better organization
$grouped_members = [];
foreach ($committee_members as $member) {
    $role_key = strtolower(str_replace(' ', '_', $member['role']));
    if (!isset($grouped_members[$role_key])) {
        $grouped_members[$role_key] = [];
    }
    $grouped_members[$role_key][] = $member;
}

// Get current academic year
$current_academic_year = date('Y') . '-' . (date('Y') + 1);

$page_title = "Executive Committee - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="icon" href="assets/images/logo.png">
    <style>
        :root {
            --primary: #0056b3;
            --primary-dark: #003d82;
            --primary-light: #4d8be6;
            --secondary: #1e88e5;
            --accent: #0d47a1;
            --light: #f8fafc;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
            --gray-900: #212529;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--gray-900);
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            overflow-x: hidden;
            font-size: 14px;
        }

        /* Header & Navigation */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow-md);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logos {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .logo {
            height: 40px;
            width: auto;
            transition: var(--transition);
        }

        .logo-rp {
            max-width: 100px;
        }

        .logo-rpsu {
            max-width: 60px;
        }

        .brand-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
        }

        .brand-text p {
            font-size: 0.75rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            padding: 0.5rem 0;
        }

        .nav-links a:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 0;
            background: var(--gradient-primary);
            transition: var(--transition);
            border-radius: 1px;
        }

        .nav-links a:hover:after {
            width: 100%;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a.active {
            color: var(--primary);
        }

        .nav-links a.active:after {
            width: 100%;
        }

        .login-buttons {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .login-btn {
            padding: 0.6rem 1.25rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .btn-student {
            background: var(--gradient-secondary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-committee {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .login-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 80px auto 0;
            padding: 2rem 1.5rem;
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            position: relative;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 1rem;
            letter-spacing: -0.025em;
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--primary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 1.1rem;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto;
        }

        .academic-year {
            display: inline-block;
            background: var(--gradient-primary);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 1rem;
            box-shadow: var(--shadow-sm);
        }

        /* Members Grid */
        .members-section {
            margin-bottom: 4rem;
        }

        .section-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .members-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        .member-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .member-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .member-image-container {
            height: 280px;
            width: 100%;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
        }

        .member-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            transition: var(--transition);
        }

        .member-card:hover .member-image {
            transform: scale(1.05);
        }

        .member-avatar {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            color: white;
            font-size: 4rem;
        }

        .member-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }

        .member-content {
            padding: 1.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .member-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .member-role {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            background: var(--gray-100);
            border-radius: 20px;
            display: inline-block;
        }

        .member-info {
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .info-item i {
            color: var(--primary);
            width: 16px;
        }

        .member-bio {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .member-contact {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }

        .contact-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gray-100);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .contact-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Role Sections */
        .role-section {
            margin-bottom: 3rem;
        }

        .role-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
        }

        .role-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .role-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        .role-description {
            color: var(--gray-600);
            font-size: 0.95rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-600);
            background: var(--white);
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--gray-600);
        }

        .empty-state p {
            font-size: 1rem;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Footer */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 3rem 1.5rem 1.5rem;
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1000px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 2rem;
        }

        .footer-logo {
            margin-bottom: 1rem;
        }

        .footer-logo .logo {
            height: 35px;
            filter: brightness(0) invert(1);
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .social-links {
            display: flex;
            gap: 0.75rem;
        }

        .social-links a {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--warning);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .footer-links a:hover {
            color: var(--warning);
            padding-left: 3px;
        }

        .footer-bottom {
            max-width: 1000px;
            margin: 0 auto;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            margin-top: 2rem;
            font-size: 0.75rem;
        }

        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Enhanced Mobile Responsiveness */

/* Base mobile-first improvements */
@media (max-width: 768px) {
    /* Header & Navigation */
    .header {
        padding: 0.5rem 0;
    }
    
    .nav-container {
        flex-direction: column;
        gap: 0.75rem;
        padding: 0 1rem;
    }
    
    .logo-section {
        width: 100%;
        justify-content: center;
        text-align: center;
    }
    
    .logos {
        justify-content: center;
    }
    
    .brand-text h1 {
        font-size: 1.25rem;
    }
    
    .brand-text p {
        font-size: 0.7rem;
    }
    
    .nav-links {
        gap: 0.75rem;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
    }
    
    .nav-links a {
        font-size: 0.8rem;
        padding: 0.25rem 0.5rem;
    }
    
    .login-buttons {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .login-btn {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
    }
    
    /* Main Content */
    .main-container {
        margin-top: 120px; /* Increased for mobile header height */
        padding: 1rem;
    }
    
    .page-header {
        margin-bottom: 2rem;
    }
    
    .page-title {
        font-size: 1.75rem;
        line-height: 1.2;
    }
    
    .page-subtitle {
        font-size: 0.9rem;
        line-height: 1.4;
    }
    
    .academic-year {
        font-size: 0.8rem;
        padding: 0.4rem 1.25rem;
    }
    
    /* Statistics */
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
    }
    
    /* Members Grid */
    .members-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .member-card {
        margin-bottom: 0;
    }
    
    .member-image-container {
        height: 220px;
    }
    
    .member-content {
        padding: 1.25rem;
    }
    
    .member-name {
        font-size: 1.1rem;
    }
    
    .member-role {
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
    }
    
    .info-item {
        font-size: 0.8rem;
    }
    
    .member-bio {
        font-size: 0.8rem;
    }
    
    .contact-btn {
        width: 32px;
        height: 32px;
        font-size: 0.75rem;
    }
    
    /* Section Titles */
    .section-title {
        font-size: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    /* Role Sections */
    .role-header {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    
    .role-title {
        font-size: 1.25rem;
    }
    
    .role-description {
        font-size: 0.85rem;
        text-align: center;
    }
    
    /* Footer */
    .footer {
        padding: 2rem 1rem 1rem;
        margin-top: 3rem;
    }
    
    .footer-content {
        grid-template-columns: 1fr;
        gap: 1.5rem;
        text-align: center;
    }
    
    .footer-logo {
        display: flex;
        justify-content: center;
    }
    
    .social-links {
        justify-content: center;
    }
    
    .footer-bottom {
        margin-top: 1.5rem;
        font-size: 0.7rem;
    }
}

/* Small mobile devices */
@media (max-width: 480px) {
    .header {
        padding: 0.4rem 0;
    }
    
    .nav-container {
        padding: 0 0.75rem;
    }
    
    .logo {
        height: 32px;
    }
    
    .brand-text h1 {
        font-size: 1.1rem;
    }
    
    .nav-links {
        gap: 0.5rem;
    }
    
    .nav-links a {
        font-size: 0.75rem;
    }
    
    .login-btn {
        padding: 0.4rem 0.8rem;
        font-size: 0.7rem;
    }
    
    .main-container {
        margin-top: 130px;
        padding: 0.75rem;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        font-size: 0.85rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .member-image-container {
        height: 200px;
    }
    
    .member-content {
        padding: 1rem;
    }
    
    .member-name {
        font-size: 1rem;
    }
    
    .info-item {
        font-size: 0.75rem;
    }
    
    .member-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.6rem;
        top: 0.75rem;
        right: 0.75rem;
    }
    
    .footer {
        padding: 1.5rem 0.75rem 0.75rem;
    }
    
    .footer-heading {
        font-size: 0.9rem;
    }
    
    .footer-links a {
        font-size: 0.8rem;
    }
}

/* Very small devices (e.g., iPhone 5/SE) */
@media (max-width: 320px) {
    .nav-links {
        gap: 0.25rem;
    }
    
    .nav-links a {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
    
    .login-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .login-btn {
        width: 100%;
        max-width: 200px;
        justify-content: center;
    }
    
    .page-title {
        font-size: 1.3rem;
    }
    
    .member-image-container {
        height: 180px;
    }
}

/* Touch-friendly improvements for mobile */
@media (hover: none) and (pointer: coarse) {
    .member-card:hover {
        transform: none;
    }
    
    .contact-btn:hover {
        transform: none;
    }
    
    .login-btn:hover {
        transform: none;
    }
    
    /* Increase tap target sizes */
    .nav-links a {
        padding: 0.5rem 0.75rem;
    }
    
    .contact-btn {
        width: 44px;
        height: 44px;
    }
    
    .social-links a {
        width: 44px;
        height: 44px;
    }
}

/* Performance optimizations for mobile */
@media (max-width: 768px) {
    .member-card {
        transform: none !important; /* Disable hover transforms on mobile */
    }
    
    .member-image {
        transform: none !important; /* Disable image scaling on mobile */
    }
}

/* Print styles */
@media print {
    .header, .footer, .login-buttons, .contact-btn, .member-badge {
        display: none;
    }
    
    .main-container {
        margin-top: 0;
    }
    
    body {
        background: white;
        font-size: 12pt;
    }
    
    .member-card {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid #ccc;
    }
}
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU" class="logo logo-rpsu">
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="announcements.php">Announcements</a>
                <a href="news.php">News</a>
                <a href="events.php">Events</a>
                <a href="committee.php" class="active">Committee</a>
                <a href="gallery.php">Gallery</a>
            </nav>
            <div class="login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Executive Committee</h1>
            <p class="page-subtitle">
                Meet your dedicated student representatives working tirelessly to enhance campus life, 
                address student concerns, and foster a vibrant academic community at RP Musanze College.
            </p>
            <div class="academic-year">
                Academic Year <?php echo $current_academic_year; ?>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($committee_members); ?></div>
                <div class="stat-label">Committee Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_column($committee_members, 'department_name'))); ?></div>
                <div class="stat-label">Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_column($committee_members, 'program_name'))); ?></div>
                <div class="stat-label">Programs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $current_academic_year; ?></div>
                <div class="stat-label">Current Academic Year</div>
            </div>
        </div>

        <!-- Committee Members -->
        <section class="members-section">
            <h2 class="section-title">Meet Your Representatives</h2>
            
            <?php if (empty($committee_members)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>Committee Information Coming Soon</h3>
                    <p>The committee member information is being updated. Please check back later to meet your representatives.</p>
                </div>
            <?php else: ?>
                <!-- Display all members in a 3-column grid -->
                <div class="members-grid">
                    <?php foreach ($committee_members as $member): ?>
                        <div class="member-card">
                            <div class="member-image-container">
                                <?php if (!empty($member['photo_url']) || !empty($member['user_avatar'])): ?>
                                    <?php 
                                        $image_url = !empty($member['photo_url']) ? $member['photo_url'] : $member['user_avatar'];
                                    ?>
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                         alt="<?php echo htmlspecialchars($member['name']); ?>" 
                                         class="member-image"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="member-avatar" style="display: none;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="member-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="member-badge"><?php echo $member['role']; ?></div>
                            </div>
                            
                            <div class="member-content">
                                <h3 class="member-name"><?php echo htmlspecialchars($member['name']); ?></h3>
                                <?php if (!empty($member['reg_number'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-id-card"></i>
                                        <span><?php echo htmlspecialchars($member['reg_number']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="member-info">
                                    <?php if (!empty($member['department_name'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-building"></i>
                                            <span><?php echo htmlspecialchars($member['department_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($member['program_name'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-book"></i>
                                            <span><?php echo htmlspecialchars($member['program_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($member['academic_year'])): ?>
                                        <div class="info-item">
                                            <i class="fas fa-calendar"></i>
                                            <span>Year <?php echo htmlspecialchars($member['academic_year']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($member['bio'])): ?>
                                    <div class="member-bio">
                                        <?php echo htmlspecialchars($member['bio']); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($member['portfolio_description'])): ?>
                                    <div class="member-bio">
                                        <strong>Portfolio:</strong> <?php echo htmlspecialchars($member['portfolio_description']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="member-contact">
                                    <?php if (!empty($member['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>" class="contact-btn" title="Email">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($member['phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($member['phone']); ?>" class="contact-btn" title="Phone">
                                            <i class="fas fa-phone"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

 <footer class="footer">
    <div class="footer-content">
        <div class="footer-info">
            <div class="footer-logo">
                <img src="assets/images/rp_logo.png" alt="RP Musanze" class="logo">
            </div>
            <p class="footer-description">
                Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
            </p>
            <div class="social-links">
                <a href="https://twitter.com/MusanzecollegSU" target="_blank"><i class="fab fa-twitter"></i></a>
                <a href="https://www.facebook.com/RP-Musanze-College" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.linkedin.com/in/rp-musanze-college-3963b0203" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Quick Links</h4>
            <ul class="footer-links">
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="news.php">Campus News</a></li>
                <li><a href="events.php">Events</a></li>
                <li><a href="committee.php">Committee</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Student Resources</h4>
            <ul class="footer-links">
                <li><a href="https://www.rp.ac.rw/announcement" target="_blank">Academic Calendar</a></li>
                <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank">Campus Map</a></li>
                <li><a href="../assets/rp_handbook.pdf">Student Handbook</a></li>
                <li><a href="gallery.php">Gallery</a></li>
            </ul>
        </div>
        <div class="footer-links-group">
            <h4 class="footer-heading">Contact Info</h4>
            <ul class="footer-links">
                <li><i class="fas fa-map-marker-alt"></i> Rwanda Polytechnic Musanze College Student Union</li>
                <li><i class="fas fa-phone"></i> +250 788 123 456</li>
                <li><i class="fas fa-envelope"></i> iprcmusanzesu@gmail.com</li>
                <li><i class="fas fa-clock"></i> Mon - Fri: 8:00 - 17:00</li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2025 Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
    </div>
</footer>


    <script>
        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe cards for animation
        document.querySelectorAll('.member-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Image error handling
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.member-image').forEach(img => {
                img.addEventListener('error', function() {
                    this.style.display = 'none';
                    const avatar = this.nextElementSibling;
                    if (avatar && avatar.classList.contains('member-avatar')) {
                        avatar.style.display = 'flex';
                    }
                });
            });
        });
    </script>
</body>
</html>
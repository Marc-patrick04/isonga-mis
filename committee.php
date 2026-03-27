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

// Get current academic year
$current_academic_year = date('Y') . '-' . (date('Y') + 1);

$page_title = "Executive Committee - RPSU Musanze College";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Meet your dedicated student representatives working tirelessly to enhance campus life at RP Musanze College.">
    <title><?php echo $page_title; ?></title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" as="style">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    
    <!-- Favicon -->
    <link rel="icon" href="assets/images/logo.png" type="image/png">
    
    <style>
        /* CSS Variables - Matching index.php */
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
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Spacing */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            
            /* Typography */
            --text-xs: 0.7rem;
            --text-sm: 0.8rem;
            --text-base: 0.9rem;
            --text-md: 1rem;
            --text-lg: 1.1rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.75rem;
        }

        @media (min-width: 768px) {
            :root {
                --space-md: 1.5rem;
                --space-lg: 2rem;
                --space-xl: 3rem;
                --text-sm: 0.875rem;
                --text-base: 1rem;
                --text-md: 1.125rem;
                --text-lg: 1.25rem;
                --text-xl: 1.5rem;
                --text-2xl: 1.875rem;
                --text-3xl: 2.25rem;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            color: var(--gray-900);
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            overflow-x: hidden;
            font-size: var(--text-base);
        }

        /* Header & Navigation - Matching index.php */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 0.5rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow-md);
            padding: 0.4rem 0;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
            gap: var(--space-sm);
        }

        @media (min-width: 768px) {
            .nav-container {
                padding: 0 1.5rem;
            }
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            min-width: 0;
        }

        .logos {
            display: flex;
            gap: var(--space-xs);
            align-items: center;
            flex-shrink: 0;
        }

        .logo {
            height: 32px;
            width: auto;
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .logo {
                height: 40px;
            }
        }

        .brand-text {
            flex-shrink: 1;
            min-width: 0;
        }

        .brand-text h1 {
            font-size: 1rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.025em;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .brand-text h1 {
                font-size: 1.4rem;
            }
        }

        .brand-text p {
            font-size: 0.65rem;
            color: var(--gray-600);
            font-weight: 500;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .brand-text p {
                font-size: 0.75rem;
            }
        }

        /* Desktop Navigation */
        .desktop-nav {
            display: none;
            align-items: center;
            gap: var(--space-md);
        }

        @media (min-width: 768px) {
            .desktop-nav {
                display: flex;
            }
        }

        .nav-links {
            display: flex;
            gap: var(--space-lg);
        }

        .nav-links a {
            color: var(--gray-800);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            padding: var(--space-xs) 0;
            white-space: nowrap;
        }

        .nav-links a::after {
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

        .nav-links a:hover::after,
        .nav-links a.active::after {
            width: 100%;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--primary);
        }

        .login-buttons {
            display: flex;
            gap: var(--space-xs);
            align-items: center;
        }

        .login-btn {
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .login-btn {
                padding: 0.6rem 1.25rem;
                font-size: 0.8rem;
                gap: 0.5rem;
            }
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

        /* Mobile Navigation */
        .mobile-menu-btn {
            display: flex;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            font-size: 1.25rem;
            color: var(--gray-800);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        .mobile-menu-btn:hover {
            background: var(--gray-100);
        }

        .mobile-menu {
            position: fixed;
            top: 60px;
            left: 0;
            width: 100%;
            height: calc(100vh - 60px);
            background: var(--white);
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (min-width: 768px) {
            .mobile-menu {
                display: none;
            }
        }

        .mobile-menu.active {
            transform: translateX(0);
        }

        .mobile-nav {
            padding: var(--space-sm);
        }

        .mobile-nav .nav-links {
            flex-direction: column;
            gap: 0;
        }

        .mobile-nav .nav-links a {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.9rem;
        }

        .mobile-nav .nav-links a:last-child {
            border-bottom: none;
        }

        .mobile-login-buttons {
            padding: var(--space-sm);
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }

        .mobile-login-buttons .login-btn {
            width: 100%;
            justify-content: center;
            padding: 0.75rem;
            font-size: 0.85rem;
        }

        /* Main Content */
        .main-container {
            max-width: 1200px;
            margin: 70px auto 0;
            padding: 1.5rem 1rem;
        }

        @media (min-width: 768px) {
            .main-container {
                margin: 80px auto 0;
                padding: 2rem 1.5rem;
            }
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .page-header {
                margin-bottom: 3rem;
            }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--primary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @media (min-width: 768px) {
            .page-title {
                font-size: 2.5rem;
                margin-bottom: 1rem;
            }
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
            max-width: 600px;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .page-subtitle {
                font-size: 1.1rem;
            }
        }

        .academic-year {
            display: inline-block;
            background: var(--gradient-primary);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.75rem;
            box-shadow: var(--shadow-sm);
        }

        @media (min-width: 768px) {
            .academic-year {
                padding: 0.5rem 1.5rem;
                font-size: 0.9rem;
                margin-top: 1rem;
            }
        }

        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }
        }

        @media (min-width: 768px) {
            .stats-grid {
                gap: 1.5rem;
                margin-bottom: 3rem;
            }
        }

        .stat-card {
            background: var(--white);
            padding: 1rem;
            border-radius: var(--border-radius-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .stat-card {
                padding: 1.5rem;
            }
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 2rem;
                margin-bottom: 0.5rem;
            }
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.7rem;
            font-weight: 500;
        }

        @media (min-width: 768px) {
            .stat-label {
                font-size: 0.875rem;
            }
        }

        /* Members Section */
        .members-section {
            margin-bottom: 2rem;
        }

        @media (min-width: 768px) {
            .members-section {
                margin-bottom: 4rem;
            }
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
        }

        @media (min-width: 768px) {
            .section-title {
                font-size: 1.75rem;
                margin-bottom: 2rem;
            }
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        @media (min-width: 768px) {
            .section-title:after {
                width: 60px;
                bottom: -0.75rem;
            }
        }

        .members-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .members-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }
        }

        @media (min-width: 1024px) {
            .members-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 2rem;
            }
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
            width: 100%;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .member-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
            transition: var(--transition);
            display: block;
        }

        .member-card:hover .member-image {
            transform: scale(1.03);
        }

        .member-avatar {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            color: white;
            font-size: 2.5rem;
        }

        @media (min-width: 768px) {
            .member-avatar {
                font-size: 4rem;
            }
        }

        .member-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-size: 0.65rem;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
            z-index: 2;
        }

        @media (min-width: 768px) {
            .member-badge {
                top: 1rem;
                right: 1rem;
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
        }

        .member-content {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .member-content {
                padding: 1.5rem;
            }
        }

        .member-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.35rem;
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .member-name {
                font-size: 1.2rem;
                margin-bottom: 0.5rem;
            }
        }

        .member-role {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.7rem;
            margin-bottom: 0.75rem;
            padding: 0.3rem 0.8rem;
            background: var(--gray-100);
            border-radius: 20px;
            display: inline-block;
        }

        @media (min-width: 768px) {
            .member-role {
                font-size: 0.9rem;
                margin-bottom: 1rem;
                padding: 0.5rem 1rem;
            }
        }

        .member-info {
            margin-bottom: 0.75rem;
            flex-grow: 1;
        }

        @media (min-width: 768px) {
            .member-info {
                margin-bottom: 1rem;
            }
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            color: var(--gray-600);
            font-size: 0.7rem;
        }

        @media (min-width: 768px) {
            .info-item {
                margin-bottom: 0.5rem;
                font-size: 0.875rem;
            }
        }

        .info-item i {
            color: var(--primary);
            width: 14px;
        }

        @media (min-width: 768px) {
            .info-item i {
                width: 16px;
            }
        }

        .member-bio {
            color: var(--gray-600);
            font-size: 0.7rem;
            line-height: 1.4;
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .member-bio {
                font-size: 0.875rem;
                line-height: 1.5;
                margin-bottom: 1rem;
            }
        }

        .member-contact {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .member-contact {
                padding-top: 1rem;
            }
        }

        .contact-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gray-100);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.75rem;
        }

        @media (min-width: 768px) {
            .contact-btn {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }
        }

        .contact-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--gray-600);
            background: var(--white);
            border-radius: var(--border-radius-lg);
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            grid-column: 1 / -1;
        }

        @media (min-width: 768px) {
            .empty-state {
                padding: 4rem 2rem;
            }
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        @media (min-width: 768px) {
            .empty-state i {
                font-size: 4rem;
                margin-bottom: 1.5rem;
            }
        }

        .empty-state h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
        }

        @media (min-width: 768px) {
            .empty-state h3 {
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }
        }

        .empty-state p {
            font-size: 0.8rem;
            max-width: 400px;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .empty-state p {
                font-size: 1rem;
            }
        }

        /* Footer - Matching index.php */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 2rem 1rem 1rem;
            margin-top: 2rem;
        }

        @media (min-width: 768px) {
            .footer {
                padding: 3rem 1.5rem 1.5rem;
                margin-top: 4rem;
            }
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 768px) {
            .footer-content {
                grid-template-columns: 2fr 1fr 1fr 1fr;
                gap: 2rem;
            }
        }

        .footer-logo {
            margin-bottom: 0.75rem;
        }

        .footer-logo .logo {
            height: 30px;
            filter: brightness(0) invert(1);
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .footer-description {
                font-size: 0.875rem;
                margin-bottom: 1.5rem;
            }
        }

        .social-links {
            display: flex;
            gap: 0.6rem;
        }

        .social-links a {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.8rem;
        }

        @media (min-width: 768px) {
            .social-links a {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
                border-radius: 8px;
            }
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }

        .footer-heading {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--warning);
        }

        @media (min-width: 768px) {
            .footer-heading {
                font-size: 1rem;
                margin-bottom: 1rem;
            }
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.4rem;
        }

        @media (min-width: 768px) {
            .footer-links li {
                margin-bottom: 0.5rem;
            }
        }

        .footer-links a {
            color: #9ca3af;
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        @media (min-width: 768px) {
            .footer-links a {
                font-size: 0.875rem;
                gap: 0.5rem;
            }
        }

        .footer-links a:hover {
            color: var(--warning);
            padding-left: 3px;
        }

        .footer-bottom {
            max-width: 1200px;
            margin: 1rem auto 0;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            font-size: 0.65rem;
        }

        @media (min-width: 768px) {
            .footer-bottom {
                margin-top: 2rem;
                padding-top: 1.5rem;
                font-size: 0.75rem;
            }
        }

        /* Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .member-card {
            animation: fadeInUp 0.6s ease-out;
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .member-card:hover {
                transform: none;
            }
            
            .member-card:hover .member-image {
                transform: none;
            }
            
            .contact-btn {
                width: 44px;
                height: 44px;
            }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
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
    <!-- Header - Matching index.php -->
    <header class="header" id="header">
        <div class="nav-container">
            <div class="logo-section">
                <div class="logos">
                    <img src="assets/images/logo.png" alt="RPSU Logo" class="logo" loading="lazy">
                </div>
                <div class="brand-text">
                    <h1>Isonga</h1>
                    <p>RPSU Management System</p>
                </div>
            </div>
            
            <!-- Desktop Navigation -->
            <div class="desktop-nav">
                <nav class="nav-links" aria-label="Main Navigation">
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
            
            <!-- Mobile Menu Button -->
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle mobile menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu" aria-hidden="true">
            <div class="mobile-nav">
                <nav class="nav-links" aria-label="Mobile Navigation">
                    <a href="index.php">Home</a>
                    <a href="announcements.php">Announcements</a>
                    <a href="news.php">News</a>
                    <a href="events.php">Events</a>
                    <a href="committee.php" class="active">Committee</a>
                    <a href="gallery.php">Gallery</a>
                </nav>
            </div>
            <div class="mobile-login-buttons">
                <a href="auth/student_login.php" class="login-btn btn-student">
                    <i class="fas fa-user-graduate"></i> Student Portal
                </a>
                <a href="auth/login.php" class="login-btn btn-committee">
                    <i class="fas fa-users"></i> Committee Portal
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header" data-aos="fade-up">
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
        <div class="stats-grid" data-aos="fade-up" data-aos-delay="100">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($committee_members); ?></div>
                <div class="stat-label">Committee Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_filter(array_column($committee_members, 'department_name')))); ?></div>
                <div class="stat-label">Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_filter(array_column($committee_members, 'program_name')))); ?></div>
                <div class="stat-label">Programs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $current_academic_year; ?></div>
                <div class="stat-label">Academic Year</div>
            </div>
        </div>

        <!-- Committee Members -->
        <section class="members-section">
            <h2 class="section-title" data-aos="fade-up" data-aos-delay="200">Meet Your Representatives</h2>
            
            <?php if (empty($committee_members)): ?>
                <div class="empty-state" data-aos="fade-up" data-aos-delay="300">
                    <i class="fas fa-users"></i>
                    <h3>Committee Information Coming Soon</h3>
                    <p>The committee member information is being updated. Please check back later to meet your representatives.</p>
                </div>
            <?php else: ?>
                <div class="members-grid">
                    <?php foreach ($committee_members as $index => $member): ?>
                        <div class="member-card" data-aos="fade-up" data-aos-delay="<?php echo 300 + ($index * 50); ?>">
                            <div class="member-image-container">
                                <?php if (!empty($member['photo_url']) || !empty($member['user_avatar'])): ?>
                                    <?php 
                                        $image_url = !empty($member['photo_url']) ? $member['photo_url'] : $member['user_avatar'];
                                    ?>
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                         alt="<?php echo htmlspecialchars($member['name']); ?>" 
                                         class="member-image"
                                         loading="lazy"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="member-avatar" style="display: none;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="member-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="member-badge"><?php echo htmlspecialchars($member['role']); ?></div>
                            </div>
                            
                            <div class="member-content">
                                <h3 class="member-name"><?php echo htmlspecialchars($member['name']); ?></h3>
                                
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
                                        <?php echo htmlspecialchars(substr($member['bio'], 0, 120)) . (strlen($member['bio']) > 120 ? '...' : ''); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($member['portfolio_description'])): ?>
                                    <div class="member-bio">
                                        <strong>Portfolio:</strong> <?php echo htmlspecialchars(substr($member['portfolio_description'], 0, 100)) . (strlen($member['portfolio_description']) > 100 ? '...' : ''); ?>
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

    <!-- Footer - Matching index.php -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-info">
                <div class="footer-logo">
                    <img src="assets/images/rp_logo.png" alt="RP Musanze College" class="logo">
                </div>
                <p class="footer-description">
                    Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
                </p>
                <div class="social-links">
                    <a href="https://twitter.com/MusanzecollegSU" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="https://www.facebook.com/RP-Musanze-College" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="https://www.linkedin.com/in/rp-musanze-college-3963b0203" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="https://www.instagram.com/rpmusanzecollege_su" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
            </div>
            
            <div class="footer-links-group">
                <h4 class="footer-heading">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="announcements.php"><i class="fas fa-chevron-right"></i> Announcements</a></li>
                    <li><a href="news.php"><i class="fas fa-chevron-right"></i> Campus News</a></li>
                    <li><a href="events.php"><i class="fas fa-chevron-right"></i> Events</a></li>
                    <li><a href="committee.php"><i class="fas fa-chevron-right"></i> Committee</a></li>
                </ul>
            </div>
            
            <div class="footer-links-group">
                <h4 class="footer-heading">Student Resources</h4>
                <ul class="footer-links">
                    <li><a href="https://www.rp.ac.rw/announcement" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Academic Calendar</a></li>
                    <li><a href="https://www.google.com/maps/search/rp+musanze+college" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> Campus Map</a></li>
                    <li><a href="../assets/rp_handbook.pdf"><i class="fas fa-chevron-right"></i> Student Handbook</a></li>
                    <li><a href="gallery.php"><i class="fas fa-chevron-right"></i> Gallery</a></li>
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

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true,
            offset: 100
        });

        // Header scroll effect
        const header = document.getElementById('header');
        
        function updateHeader() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }
        
        window.addEventListener('scroll', updateHeader);
        updateHeader();

        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuIcon = mobileMenuBtn.querySelector('i');
        
        function toggleMobileMenu() {
            const isExpanded = mobileMenuBtn.getAttribute('aria-expanded') === 'true';
            mobileMenuBtn.setAttribute('aria-expanded', !isExpanded);
            mobileMenu.setAttribute('aria-hidden', isExpanded);
            mobileMenu.classList.toggle('active');
            
            if (mobileMenu.classList.contains('active')) {
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-times');
                document.body.style.overflow = 'hidden';
            } else {
                menuIcon.classList.remove('fa-times');
                menuIcon.classList.add('fa-bars');
                document.body.style.overflow = '';
            }
        }
        
        mobileMenuBtn.addEventListener('click', toggleMobileMenu);
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            if (mobileMenu.classList.contains('active') && 
                !mobileMenu.contains(event.target) && 
                !mobileMenuBtn.contains(event.target)) {
                toggleMobileMenu();
            }
        });
        
        // Close on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && mobileMenu.classList.contains('active')) {
                toggleMobileMenu();
            }
        });
        
        // Close mobile menu on window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && mobileMenu.classList.contains('active')) {
                toggleMobileMenu();
            }
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
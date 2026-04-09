<?php
session_start();
require_once 'config/database.php';

// Enhanced database handling with error logging
function safeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}

// Simple helper function to get correct image URL - NO path manipulation
function getImageUrl($path) {
    if (empty($path)) {
        return '';
    }
    
    // If it's already a full URL starting with http
    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }
    
    // If path starts with /, use as is (absolute path from root)
    if (strpos($path, '/') === 0) {
        return $path;
    }
    
    // Otherwise, assume it's relative to the site root
    // Remove any leading ../ or ./
    $path = preg_replace('/^(\.\.\/|\.\/)+/', '', $path);
    
    // For production, use relative path without modifying
    return $path;
}

// Get announcements from database
$announcements = safeQuery($pdo, 
    "SELECT * FROM announcements 
     WHERE status = 'published' 
     ORDER BY created_at DESC LIMIT 3"
);

// Get news from database (with image for background)
$news = safeQuery($pdo,
    "SELECT * FROM news 
     WHERE status = 'published' 
     ORDER BY created_at DESC LIMIT 3"
);

// Get committee members
$committee_members = safeQuery($pdo,
    "SELECT * FROM committee_members 
     WHERE status = 'active' 
     ORDER BY role_order, name ASC LIMIT 4"
);

// Get upcoming events - PostgreSQL compatible (with image for background)
$events = safeQuery($pdo,
    "SELECT * FROM events 
     WHERE event_date >= CURRENT_DATE 
     AND status = 'published' 
     ORDER BY event_date ASC LIMIT 3"
);

// Get hero section images from hero table for Quick Links
try {
    $hero_items = $pdo->query("SELECT * FROM hero WHERE is_active = TRUE ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Create associative array keyed by slug for easy access
    $hero_map = [];
    foreach ($hero_items as $hero_item) {
        $hero_map[$hero_item['slug']] = $hero_item;
    }
} catch (PDOException $e) {
    $hero_map = [];
    $hero_items = [];
}

// Get statistics with caching consideration
$student_count = 0;
$resolved_tickets = 0;
$active_committees = 0;
$active_clubs = 0;

$stat_queries = [
    'student_count' => "SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'",
    'resolved_tickets' => "SELECT COUNT(*) as total FROM tickets WHERE status = 'resolved'",
    'active_committees' => "SELECT COUNT(*) as total FROM committee_members WHERE status = 'active'",
    'active_clubs' => "SELECT COUNT(*) as total FROM clubs WHERE status = 'active'"
];

foreach ($stat_queries as $key => $query) {
    try {
        $result = $pdo->query($query)->fetch();
        $$key = $result['total'] ?? 0;
    } catch (PDOException $e) {
        $$key = $key === 'student_count' ? 2000 : 
                ($key === 'resolved_tickets' ? 150 : 
                ($key === 'active_committees' ? 18 : 12));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="description" content="Isonga - RPSU Management System for Rwanda Polytechnic Musanze College.">
    <title>Isonga - RPSU Management System | RP Musanze College</title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" as="style">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" 
          integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" 
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    
    <!-- Favicon -->
    <link rel="icon" href="assets/images/logo.png" type="image/png">
    
    <style>
        /* CSS Variables - Enhanced with mobile-first sizing */
        :root {
            /* Colors */
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
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            --gradient-hero: linear-gradient(135deg, rgba(63, 118, 177, 0.85) 0%, rgba(13, 72, 161, 0.75) 100%);
            
            /* Shadows */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            
            /* Border Radius */
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --border-radius-xl: 16px;
            
            /* Transitions */
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Spacing - Mobile first (smaller) */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            
            /* Typography - Mobile first (smaller) */
            --text-xs: 0.7rem;
            --text-sm: 0.8rem;
            --text-base: 0.9rem;
            --text-md: 1rem;
            --text-lg: 1.1rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.75rem;
        }

        /* Desktop typography (larger) */
        @media (min-width: 768px) {
            :root {
                --space-xs: 0.5rem;
                --space-sm: 1rem;
                --space-md: 1.5rem;
                --space-lg: 2rem;
                --space-xl: 3rem;
                --text-xs: 0.75rem;
                --text-sm: 0.875rem;
                --text-base: 1rem;
                --text-md: 1.125rem;
                --text-lg: 1.25rem;
                --text-xl: 1.5rem;
                --text-2xl: 1.875rem;
                --text-3xl: 2.25rem;
            }
        }

        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        *:focus {
            outline: 2px solid var(--primary-light);
            outline-offset: 2px;
        }

        html {
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
            -moz-text-size-adjust: 100%;
            text-size-adjust: 100%;
            font-size: 16px;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.5;
            color: var(--gray-900);
            background: var(--white);
            overflow-x: hidden;
            font-size: var(--text-base);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Loading Screen */
        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--white);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .loading-screen.fade-out {
            opacity: 0;
            visibility: hidden;
        }

        .loader-container {
            text-align: center;
            animation: fadeInUp 0.6s ease-out;
        }

        .loader-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 1.5rem;
            animation: pulse 1.5s ease-in-out infinite;
        }

        .loader-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .loader-text {
            font-size: 1.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .loader-subtext {
            color: var(--gray-600);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .loader-progress {
            width: 200px;
            height: 4px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin: 0 auto;
        }

        .loader-progress-bar {
            height: 100%;
            width: 0%;
            background: var(--gradient-primary);
            border-radius: 4px;
            animation: loading 3s ease-out forwards;
        }

        .loader-dots {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .loader-dot {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.4;
            animation: dotPulse 1.5s ease-in-out infinite;
        }

        .loader-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        .loader-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes loading {
            0% { width: 0%; }
            20% { width: 30%; }
            40% { width: 55%; }
            60% { width: 75%; }
            80% { width: 90%; }
            100% { width: 100%; }
        }

        @keyframes dotPulse {
            0%, 100% {
                opacity: 0.4;
                transform: scale(1);
            }
            50% {
                opacity: 1;
                transform: scale(1.2);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

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

        /* Main Content - Initially Hidden */
        .main-content {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .main-content.visible {
            opacity: 1;
            visibility: visible;
        }

        /* Typography */
        h1, h2, h3, h4, h5, h6 {
            line-height: 1.2;
            font-weight: 700;
        }

        h1 { font-size: var(--text-3xl); }
        h2 { font-size: var(--text-2xl); }
        h3 { font-size: var(--text-xl); }
        h4 { font-size: var(--text-lg); }
        h5 { font-size: var(--text-md); }
        h6 { font-size: var(--text-base); }

        p {
            margin-bottom: 0.75rem;
            font-size: var(--text-base);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        /* Utility Classes */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 var(--space-md);
        }

        /* Header & Navigation - Mobile Optimized */
        .header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--space-sm);
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
            overflow: hidden;
            text-overflow: ellipsis;
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
            overflow: hidden;
            text-overflow: ellipsis;
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
        .nav-links a:focus::after {
            width: 100%;
        }

        .nav-links a:hover,
        .nav-links a:focus {
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

        .login-btn:hover,
        .login-btn:focus {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Mobile Navigation - Optimized */
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
            transition: var(--transition-fast);
        }

        @media (min-width: 768px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        .mobile-menu-btn:hover,
        .mobile-menu-btn:focus {
            background: var(--gray-100);
        }

        .mobile-menu {
            display: block;
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

        /* Hero Section - Mobile Optimized */
        .hero {
            margin-top: 60px;
            min-height: auto;
            padding: var(--space-xl) 0;
            display: flex;
            align-items: center;
            position: relative;
            background-color: var(--primary-dark);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        /* Fallback if image doesn't load */
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-hero);
            z-index: 1;
        }

        .hero > * {
            position: relative;
            z-index: 2;
        }

        @media (min-width: 768px) {
            .hero {
                margin-top: 70px;
                min-height: 85vh;
                padding: 0;
            }
        }

        .hero-content {
            width: 100%;
            padding: var(--space-lg) var(--space-sm);
            color: white;
            text-align: center;
        }

        @media (min-width: 768px) {
            .hero-content {
                padding: var(--space-xl) var(--space-md);
            }
        }

        .hero-text {
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-text h2 {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 0.75rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        @media (min-width: 768px) {
            .hero-text h2 {
                font-size: 2.5rem;
                margin-bottom: 1.25rem;
            }
        }

        .hero-text p {
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            opacity: 0.95;
            font-weight: 300;
            line-height: 1.5;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        @media (min-width: 768px) {
            .hero-text p {
                font-size: 1.1rem;
                margin-bottom: 2rem;
            }
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 640px) {
            .hero-stats {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
                margin-top: 2rem;
            }
        }

        @media (min-width: 768px) {
            .hero-stats {
                gap: 1.5rem;
                margin-top: 2.5rem;
            }
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .stat-item {
                padding: 1.25rem;
                border-radius: var(--border-radius-lg);
            }
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 800;
            display: block;
            color: var(--warning);
            margin-bottom: 0.25rem;
        }

        @media (min-width: 768px) {
            .stat-number {
                font-size: 1.75rem;
            }
        }

        .stat-label {
            font-size: 0.7rem;
            opacity: 0.9;
            font-weight: 500;
        }

        @media (min-width: 768px) {
            .stat-label {
                font-size: 0.8rem;
            }
        }

        .hero-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            margin-top: 1.5rem;
            flex-wrap: wrap;
            flex-direction: column;
        }

        @media (min-width: 480px) {
            .hero-actions {
                flex-direction: row;
            }
        }

        @media (min-width: 768px) {
            .hero-actions {
                gap: 1rem;
                margin-top: 2.5rem;
            }
        }

        .btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 44px;
        }

        @media (min-width: 768px) {
            .btn {
                padding: 0.875rem 2rem;
                font-size: 0.9rem;
            }
        }

        .btn-primary {
            background: var(--warning);
            color: var(--gray-900);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 1.5px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        /* Quick Links Section */
        .quick-links {
            padding: var(--space-lg) var(--space-sm);
            background: var(--light);
        }

        @media (min-width: 768px) {
            .quick-links {
                padding: var(--space-xl) var(--space-md);
            }
        }

        .section-header {
            text-align: center;
            max-width: 600px;
            margin: 0 auto var(--space-lg);
        }

        @media (min-width: 768px) {
            .section-header {
                margin-bottom: 3rem;
            }
        }

        .section-badge {
            display: inline-block;
            background: var(--gradient-primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        @media (min-width: 768px) {
            .section-badge {
                padding: 0.4rem 1rem;
                font-size: 0.75rem;
                margin-bottom: 1rem;
            }
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.75rem;
            color: var(--gray-900);
        }

        @media (min-width: 768px) {
            .section-title {
                font-size: 2rem;
                margin-bottom: 1rem;
            }
        }

        .section-subtitle {
            font-size: 0.85rem;
            color: var(--gray-600);
            line-height: 1.5;
        }

        @media (min-width: 768px) {
            .section-subtitle {
                font-size: 1rem;
            }
        }

        .links-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .links-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }
        }

        @media (min-width: 1024px) {
            .links-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1.5rem;
            }
        }

        .link-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 0;
            text-align: center;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .link-card {
                padding: 0;
                border-radius: var(--border-radius-lg);
            }
        }

        .link-card-image {
            width: 100%;
            height: 160px;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .link-card-image {
                height: 180px;
            }
        }

        .link-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .link-card:hover .link-card-image img {
            transform: scale(1.05);
        }

        .link-card-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        @media (min-width: 768px) {
            .link-card-image-placeholder {
                font-size: 2.5rem;
            }
        }

        .link-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem auto 0.75rem;
            color: white;
            font-size: 1.1rem;
            transition: var(--transition);
        }

        @media (min-width: 768px) {
            .link-icon {
                width: 56px;
                height: 56px;
                font-size: 1.25rem;
                margin-top: 1.25rem;
                margin-bottom: 1rem;
                border-radius: var(--border-radius-lg);
            }
        }

        .link-title {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
            padding: 0 0.5rem;
        }

        @media (min-width: 768px) {
            .link-title {
                font-size: 1.1rem;
                margin-bottom: 0.75rem;
            }
        }

        .link-description {
            color: var(--gray-600);
            line-height: 1.4;
            font-size: 0.75rem;
            padding: 0 0.5rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 768px) {
            .link-description {
                font-size: 0.875rem;
                line-height: 1.5;
            }
        }

        /* Highlights Section */
        .highlights {
            padding: var(--space-lg) var(--space-sm);
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        }

        @media (min-width: 768px) {
            .highlights {
                padding: var(--space-xl) var(--space-md);
            }
        }

        .highlights-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .highlights-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 2rem;
            }
        }

        .highlight-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .highlight-card {
                border-radius: var(--border-radius-lg);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }
        }

        .highlight-image {
            height: 120px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media (min-width: 768px) {
            .highlight-image {
                height: 160px;
            }
        }

        .highlight-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .highlight-card:hover .highlight-image img {
            transform: scale(1.05);
        }

        .highlight-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        @media (min-width: 768px) {
            .highlight-image-placeholder {
                font-size: 3rem;
            }
        }

        .highlight-content {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 768px) {
            .highlight-content {
                padding: 1.5rem;
            }
        }

        .highlight-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--gray-900);
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary);
            display: inline-block;
        }

        @media (min-width: 768px) {
            .highlight-title {
                font-size: 1.25rem;
                margin-bottom: 1.25rem;
            }
        }

        .highlight-items {
            flex: 1;
        }

        .highlight-item {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 8px;
            background: var(--gray-100);
            border-left: 3px solid var(--primary);
        }

        @media (min-width: 768px) {
            .highlight-item {
                margin-bottom: 1rem;
                padding: 1rem;
            }
        }

        .highlight-date {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.75rem;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(0, 86, 179, 0.08);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            width: fit-content;
        }

        @media (min-width: 768px) {
            .highlight-date {
                font-size: 0.8rem;
                margin-bottom: 0.5rem;
                gap: 0.5rem;
            }
        }

        .highlight-item-title {
            font-weight: 700;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
            color: var(--gray-900);
            line-height: 1.3;
        }

        @media (min-width: 768px) {
            .highlight-item-title {
                font-size: 0.95rem;
                margin-bottom: 0.5rem;
            }
        }

        .highlight-excerpt {
            color: var(--gray-600);
            font-size: 0.8rem;
            line-height: 1.5;
        }

        @media (min-width: 768px) {
            .highlight-excerpt {
                font-size: 0.85rem;
                line-height: 1.5;
            }
        }

        .read-more {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.85rem;
            margin-top: auto;
            padding-top: 0.75rem;
            padding-left: 0.5rem;
            border-left: 3px solid var(--primary);
        }

        .read-more:hover {
            color: var(--primary-dark);
            padding-left: 0.75rem;
        }

        @media (min-width: 768px) {
            .read-more {
                font-size: 0.9rem;
                padding-top: 1rem;
            }
        }

        /* Committee Preview */
        .committee-preview {
            padding: var(--space-lg) var(--space-sm);
            background: var(--light);
        }

        @media (min-width: 768px) {
            .committee-preview {
                padding: var(--space-xl) var(--space-md);
            }
        }

        .committee-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .committee-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.25rem;
            }
        }

        @media (min-width: 1024px) {
            .committee-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 2rem;
            }
        }

        .member-card {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        @media (min-width: 768px) {
            .member-card {
                border-radius: var(--border-radius-lg);
            }
        }

        .member-image-container {
            width: 100%;
            position: relative;
            overflow: hidden;
            background: var(--gray-100);
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
            display: block;
        }

        .member-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            background: var(--gradient-primary);
        }

        @media (min-width: 768px) {
            .member-image-placeholder {
                font-size: 3rem;
            }
        }

        .member-content {
            padding: 1rem;
        }

        @media (min-width: 768px) {
            .member-content {
                padding: 1.5rem;
            }
        }

        .member-name {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
            color: var(--gray-900);
        }

        @media (min-width: 768px) {
            .member-name {
                font-size: 1.1rem;
                margin-bottom: 0.5rem;
            }
        }

        .member-role {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.7rem;
            margin-bottom: 0.5rem;
            padding: 0.2rem 0.6rem;
            background: var(--gray-100);
            border-radius: 20px;
            display: inline-block;
        }

        @media (min-width: 768px) {
            .member-role {
                font-size: 0.8rem;
                margin-bottom: 0.75rem;
                padding: 0.25rem 0.75rem;
            }
        }

        .member-bio {
            color: var(--gray-600);
            font-size: 0.7rem;
            line-height: 1.4;
        }

        @media (min-width: 768px) {
            .member-bio {
                font-size: 0.8rem;
                line-height: 1.5;
            }
        }

        .view-all {
            text-align: center;
            margin-top: 1.5rem;
        }

        @media (min-width: 768px) {
            .view-all {
                margin-top: 2.5rem;
            }
        }

        /* Footer - Mobile Optimized */
        .footer {
            background: var(--gray-900);
            color: white;
            padding: 2rem var(--space-sm) 1rem;
        }

        @media (min-width: 768px) {
            .footer {
                padding: 3rem var(--space-md) 1.5rem;
            }
        }

        .footer-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .footer-content {
                grid-template-columns: repeat(4, 1fr);
                gap: 2rem;
                margin-bottom: 2rem;
            }
        }

        .footer-info {
            text-align: center;
        }

        @media (min-width: 768px) {
            .footer-info {
                grid-column: span 2;
                text-align: left;
            }
        }

        .footer-logo {
            margin-bottom: 0.75rem;
        }

        .footer-logo .logo {
            height: 30px;
            filter: brightness(0) invert(1);
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .footer-logo .logo {
                margin: 0;
            }
        }

        .footer-description {
            color: #9ca3af;
            line-height: 1.5;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            text-align: center;
        }

        @media (min-width: 768px) {
            .footer-description {
                text-align: left;
                font-size: 0.875rem;
                margin-bottom: 1.5rem;
            }
        }

        .social-links {
            display: flex;
            gap: 0.6rem;
            justify-content: center;
        }

        @media (min-width: 768px) {
            .social-links {
                justify-content: flex-start;
                gap: 0.75rem;
            }
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

        .footer-heading {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--warning);
            text-align: center;
        }

        @media (min-width: 768px) {
            .footer-heading {
                font-size: 1rem;
                margin-bottom: 1rem;
                text-align: left;
            }
        }

        .footer-links {
            list-style: none;
            text-align: center;
        }

        @media (min-width: 768px) {
            .footer-links {
                text-align: left;
            }
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

        .footer-bottom {
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: #6b7280;
            font-size: 0.65rem;
        }

        @media (min-width: 768px) {
            .footer-bottom {
                font-size: 0.75rem;
                padding-top: 1.5rem;
            }
        }

        /* Color-coded icons for each section */
        .highlight-card:nth-child(1) .highlight-image {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
        }

        .highlight-card:nth-child(2) .highlight-image {
            background: linear-gradient(135deg, #1e88e5, #0d47a1);
        }

        .highlight-card:nth-child(3) .highlight-image {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .link-card:hover,
            .highlight-card:hover,
            .member-card:hover {
                transform: none;
            }
            
            .nav-links a:hover::after {
                width: 0;
            }
            
            .btn:hover,
            .login-btn:hover {
                transform: none;
            }
            
            .nav-links a,
            .footer-links a {
                padding: 0.5rem 0;
            }
            
            .btn,
            .login-btn {
                min-height: 44px;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
            
            .loader-progress-bar {
                animation: none;
                width: 100%;
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .loading-screen {
                background: #0f172a;
            }
            
            .loader-subtext {
                color: #94a3b8;
            }
            
            .header {
                background: rgba(15, 23, 42, 0.98);
                border-bottom-color: rgba(255, 255, 255, 0.05);
            }
            
            .nav-links a {
                color: #ffffff;
            }
            
            .link-card,
            .highlight-card,
            .member-card {
                background: #1e293b;
                border-color: #334155;
            }
            
            .link-title,
            .highlight-title,
            .member-name {
                color: #f1f5f9;
            }
            
            .link-description,
            .highlight-excerpt,
            .member-bio {
                color: #94a3b8;
            }
        }
        
        /* Mobile Navigation - Fixed visibility */
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
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
            display: flex;
            flex-direction: column;
            gap: 0;
            width: 100%;
        }

        .mobile-nav .nav-links a {
            display: block;
            padding: 1rem;
            color: var(--gray-800);
            background: transparent;
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            transition: var(--transition);
            border-bottom: 1px solid var(--gray-200);
        }

        .mobile-nav .nav-links a:last-child {
            border-bottom: none;
        }

        .mobile-nav .nav-links a:hover,
        .mobile-nav .nav-links a:focus,
        .mobile-nav .nav-links a.active {
            background: var(--primary);
            color: white;
            padding-left: 1.5rem;
        }

        .mobile-login-buttons {
            padding: var(--space-md);
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
            margin-top: var(--space-sm);
        }

        .mobile-login-buttons .login-btn {
            width: 100%;
            justify-content: center;
            padding: 1rem;
            font-size: 1rem;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-height: 48px;
        }

        .mobile-login-buttons .btn-student {
            background: var(--gradient-secondary);
            color: white;
        }

        .mobile-login-buttons .btn-committee {
            background: var(--gradient-primary);
            color: white;
        }

        .mobile-login-buttons .login-btn:hover,
        .mobile-login-buttons .login-btn:focus {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Dark mode support for mobile menu */
        @media (prefers-color-scheme: dark) {
            .mobile-menu {
                background: #1e293b;
            }
            
            .mobile-nav .nav-links a {
                color: #e2e8f0;
                border-bottom-color: #334155;
            }
            
            .mobile-nav .nav-links a:hover,
            .mobile-nav .nav-links a:focus,
            .mobile-nav .nav-links a.active {
                background: var(--primary);
                color: white;
            }
            
            .mobile-login-buttons {
                border-top-color: #334155;
            }
        }
        
        /* Additional image handling for mobile */
        .hero-bg-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }
        
        /* Ensure images don't overflow on mobile */
        img {
            max-width: 100%;
            height: auto;
        }
        
        /* Fix for broken images */
        img[src=""],
        img:not([src]) {
            opacity: 0;
        }
    </style>
</head>
<body>
    <!-- Loading Screen -->
    <div class="loading-screen" id="loadingScreen">
        <div class="loader-container">
            <div class="loader-logo">
                <img src="assets/images/logo.png" alt="RPSU Logo">
            </div>
            <div class="loader-text">Isonga</div>
            <div class="loader-subtext">RPSU Management System</div>
            <div class="loader-progress">
                <div class="loader-progress-bar"></div>
            </div>
            <div class="loader-dots">
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
                <div class="loader-dot"></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <header class="header" id="header">
            <div class="container nav-container">
                <div class="logo-section">
                    <div class="logos">
                        <img src="assets/images/logo.png" alt="RPSU Logo" class="logo logo-rpsu" loading="lazy">
                    </div>
                    <div class="brand-text">
                        <h1>Isonga</h1>
                        <p>RPSU Management System</p>
                    </div>
                </div>
                
                <!-- Desktop Navigation -->
                <div class="desktop-nav">
                    <nav class="nav-links" aria-label="Main Navigation">
                        <a href="announcements.php">Announcements</a>
                        <a href="news.php">News</a>
                        <a href="events.php">Events</a>
                        <a href="committee.php">Committee</a>
                        <a href="gallery.php">Gallery</a>
                    </nav>
                    <div class="login-buttons">
                        <a href="auth/student_login.php" class="login-btn btn-student">
                            <i class="fas fa-user-graduate"></i> Student
                        </a>
                        <a href="auth/login.php" class="login-btn btn-committee">
                            <i class="fas fa-users"></i> Committeee
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
                        <a href="announcements.php">Announcements</a>
                        <a href="news.php">News</a>
                        <a href="events.php">Events</a>
                        <a href="committee.php">Committee</a>
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

        <!-- Hero Section -->
        <section class="hero" aria-labelledby="hero-title" style="background-image: url('assets/images/college.jpg');">
            <div class="container hero-content">
                <div class="hero-text" data-aos="fade-up" data-aos-duration="800">
                    <h2 id="hero-title">RPSU Musanze College</h2>
                    <p>Empowering students, innovative solutions. Your voice, our priority.</p>
                    
                    <div class="hero-stats">
                        <?php 
                        $stats = [
                            // Uncomment to show stats
                            // ['number' => $student_count . '+', 'label' => 'Students'],
                            // ['number' => $resolved_tickets . '+', 'label' => 'Issues Resolved'],
                            // ['number' => $active_committees, 'label' => 'Committee Members'],
                            // ['number' => $active_clubs, 'label' => 'Active Clubs']
                        ];
                        
                        foreach ($stats as $index => $stat): ?>
                            <div class="stat-item" data-aos="fade-up" data-aos-delay="<?= ($index + 1) * 100 ?>">
                                <span class="stat-number"><?= htmlspecialchars($stat['number']) ?></span>
                                <span class="stat-label"><?= htmlspecialchars($stat['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="hero-actions">
                        <a href="auth/student_login.php" class="btn btn-primary" data-aos="fade-up" data-aos-delay="500">
                            <i class="fas fa-user-graduate"></i> Student Portal
                        </a>
                        <a href="auth/login.php" class="btn btn-secondary" data-aos="fade-up" data-aos-delay="600">
                            <i class="fas fa-users"></i> Committee Portal
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Quick Links Section -->
        <section class="quick-links" aria-labelledby="quick-links-title">
            <div class="container">
                <div class="section-header" data-aos="fade-up">
                    <span class="section-badge">Quick Access</span>
                    <h2 id="quick-links-title" class="section-title">Explore Our Platform</h2>
                    <p class="section-subtitle">Everything you need to stay connected and informed about campus life</p>
                </div>
                <div class="links-grid">
                    <?php
                    // Define default colors for each slug
                    $default_colors = [
                        'announcements' => 'linear-gradient(135deg, #ff6b6b, #ee5a24)',
                        'news' => 'linear-gradient(135deg, #1e88e5, #0d47a1)',
                        'events' => 'linear-gradient(135deg, #4caf50, #2e7d32)',
                        'committee' => 'linear-gradient(135deg, #9c27b0, #6a1b9a)'
                    ];
                    
                    // Default items in case hero table is empty
                    $default_items = [
                        [
                            'title' => 'Announcements',
                            'slug' => 'announcements',
                            'icon' => 'fa-bullhorn',
                            'link_url' => 'announcements.php',
                            'description' => 'Official communications from RPSU leadership and college administration',
                            'image_url' => '',
                            'is_active' => true
                        ],
                        [
                            'title' => 'Campus News',
                            'slug' => 'news',
                            'icon' => 'fa-newspaper',
                            'link_url' => 'news.php',
                            'description' => 'Latest happenings, achievements, and stories from around campus',
                            'image_url' => '',
                            'is_active' => true
                        ],
                        [
                            'title' => 'Events',
                            'slug' => 'events',
                            'icon' => 'fa-calendar-alt',
                            'link_url' => 'events.php',
                            'description' => 'Upcoming academic, cultural, and social events calendar',
                            'image_url' => '',
                            'is_active' => true
                        ],
                        [
                            'title' => 'Committee',
                            'slug' => 'committee',
                            'icon' => 'fa-users',
                            'link_url' => 'committee.php',
                            'description' => 'Meet your dedicated student representatives and leaders',
                            'image_url' => '',
                            'is_active' => true
                        ]
                    ];
                    
                    // Use hero items if available, otherwise use defaults
                    $link_items = !empty($hero_map) ? $hero_items : $default_items;
                    
                    foreach ($link_items as $index => $item):
                        $slug = $item['slug'];
                        // SIMPLE: Just use the image_url as is, no complex path manipulation
                        $image_url = !empty($item['image_url']) ? $item['image_url'] : '';
                        $icon = $item['icon'] ?? 'fa-link';
                        $bg_color = $default_colors[$slug] ?? 'linear-gradient(135deg, #667eea, #764ba2)';
                    ?>
                        <a href="<?= htmlspecialchars($item['link_url']) ?>" class="link-card" data-aos="fade-up" data-aos-delay="<?= ($index + 1) * 100 ?>">
                            <div class="link-card-image">
                                <?php if (!empty($image_url)): ?>
                                    <img src="<?= htmlspecialchars($image_url) ?>" 
                                         alt="<?= htmlspecialchars($item['title']) ?>"
                                         loading="lazy"
                                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'link-card-image-placeholder\' style=\'background: <?= $bg_color ?>;\'><i class=\'fas <?= htmlspecialchars($icon) ?>\'></i></div>'">
                                <?php else: ?>
                                    <div class="link-card-image-placeholder" style="background: <?= $bg_color ?>;">
                                        <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="link-icon">
                                <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                            </div>
                            <h3 class="link-title"><?= htmlspecialchars($item['title']) ?></h3>
                            <p class="link-description"><?= htmlspecialchars($item['description']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Highlights Section -->
        <section class="highlights" aria-labelledby="highlights-title">
            <div class="container">
                <div class="section-header" data-aos="fade-up">
                    <span class="section-badge">Latest Updates</span>
                    <h2 id="highlights-title" class="section-title">What's Happening</h2>
                    <p class="section-subtitle">Stay informed with the latest from RPSU and campus community</p>
                </div>
                
                <div class="highlights-grid">
                    <!-- Announcements -->
                    <div class="highlight-card" data-aos="fade-up" data-aos-delay="100">
                        <div class="highlight-image">
                            <div class="highlight-image-placeholder">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                        </div>
                        <div class="highlight-content">
                            <h3 class="highlight-title">Announcements</h3>
                            <div class="highlight-items">
                                <?php if (empty($announcements)): ?>
                                    <p class="highlight-excerpt">No announcements at this time. Check back later for updates.</p>
                                <?php else: ?>
                                    <?php foreach (array_slice($announcements, 0, 2) as $announcement): ?>
                                        <div class="highlight-item">
                                            <div class="highlight-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M j, Y', strtotime($announcement['created_at'])) ?>
                                            </div>
                                            <div class="highlight-item-title"><?= htmlspecialchars($announcement['title']) ?></div>
                                            <p class="highlight-excerpt"><?= htmlspecialchars(substr($announcement['excerpt'] ?? $announcement['content'], 0, 80)) . '...' ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <a href="announcements.php" class="read-more">
                                View All <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <!-- News -->
                    <div class="highlight-card" data-aos="fade-up" data-aos-delay="200">
                        <div class="highlight-image">
                            <div class="highlight-image-placeholder">
                                <i class="fas fa-newspaper"></i>
                            </div>
                        </div>
                        <div class="highlight-content">
                            <h3 class="highlight-title">Campus News</h3>
                            <div class="highlight-items">
                                <?php if (empty($news)): ?>
                                    <p class="highlight-excerpt">No news articles at this time. Stay tuned for updates.</p>
                                <?php else: ?>
                                    <?php foreach (array_slice($news, 0, 2) as $news_item): ?>
                                        <div class="highlight-item">
                                            <div class="highlight-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M j, Y', strtotime($news_item['created_at'])) ?>
                                            </div>
                                            <div class="highlight-item-title"><?= htmlspecialchars($news_item['title']) ?></div>
                                            <p class="highlight-excerpt"><?= htmlspecialchars(substr($news_item['excerpt'] ?? $news_item['content'], 0, 80)) . '...' ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <a href="news.php" class="read-more">
                                Read More <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Events -->
                    <div class="highlight-card" data-aos="fade-up" data-aos-delay="300">
                        <div class="highlight-image">
                            <div class="highlight-image-placeholder">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="highlight-content">
                            <h3 class="highlight-title">Upcoming Events</h3>
                            <div class="highlight-items">
                                <?php if (empty($events)): ?>
                                    <p class="highlight-excerpt">No upcoming events scheduled. Check back later for updates.</p>
                                <?php else: ?>
                                    <?php foreach (array_slice($events, 0, 2) as $event): ?>
                                        <div class="highlight-item">
                                            <div class="highlight-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M j, Y', strtotime($event['event_date'])) ?>
                                            </div>
                                            <div class="highlight-item-title"><?= htmlspecialchars($event['title']) ?></div>
                                            <p class="highlight-excerpt">
                                                <i class="fas fa-clock"></i> <?= date('g:i A', strtotime($event['start_time'])) ?><br>
                                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['location']) ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <a href="events.php" class="read-more">
                                View Calendar <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Committee Preview -->
        <section class="committee-preview" aria-labelledby="committee-title">
            <div class="container">
                <div class="section-header" data-aos="fade-up">
                    <span class="section-badge">Leadership</span>
                    <h2 id="committee-title" class="section-title">Meet Your Committee</h2>
                    <p class="section-subtitle">Your dedicated student leaders working to enhance campus experience</p>
                </div>
                
                <div class="committee-grid">
                    <?php if (empty($committee_members)): ?>
                        <div class="member-card" data-aos="fade-up" data-aos-delay="100">
                            <div class="member-image-container">
                                <div class="member-image-placeholder">
                                    <i class="fas fa-user"></i>
                                </div>
                            </div>
                            <div class="member-content">
                                <h3 class="member-name">Committee Information</h3>
                                <div class="member-role">Coming Soon</div>
                                <p class="member-bio">Committee member information will be displayed here once available.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($committee_members as $index => $member): 
                            // SIMPLE: Use the photo_url as is
                            $photo_url = !empty($member['photo_url']) ? $member['photo_url'] : '';
                        ?>
                            <div class="member-card" data-aos="fade-up" data-aos-delay="<?= ($index + 1) * 100 ?>">
                                <div class="member-image-container">
                                    <?php if (!empty($photo_url)): ?>
                                        <img src="<?= htmlspecialchars($photo_url) ?>" 
                                             alt="<?= htmlspecialchars($member['name']) ?>" 
                                             class="member-image"
                                             loading="lazy"
                                             onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'member-image-placeholder\'><i class=\'fas fa-user\'></i></div>'">
                                    <?php else: ?>
                                        <div class="member-image-placeholder">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="member-content">
                                    <h3 class="member-name"><?= htmlspecialchars($member['name']) ?></h3>
                                    <div class="member-role"><?= htmlspecialchars($member['role']) ?></div>
                                    <p class="member-bio"><?= htmlspecialchars(substr($member['bio'] ?? 'Dedicated student representative working to improve campus life.', 0, 100)) . '...' ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="view-all">
                    <a href="committee.php" class="btn btn-primary" data-aos="fade-up">
                        <i class="fas fa-users"></i> View Full Committee
                    </a>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer" aria-labelledby="footer-heading">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-info">
                        <div class="footer-logo">
                            <img src="assets/images/rp_logo.png" alt="RP Musanze College" class="logo" loading="lazy">
                        </div>
                        <p class="footer-description">
                            Isonga - RPSU Management Information System. Your direct line to student leadership at Rwanda Polytechnic Musanze College.
                        </p>
                        <div class="social-links">
                            <a href="https://twitter.com/MusanzecollegSU" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
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
                            <li><a href="assets/rp_handbook.pdf"><i class="fas fa-chevron-right"></i> Student Handbook</a></li>
                            <li><a href="gallery.php"><i class="fas fa-chevron-right"></i> Gallery</a></li>
                        </ul>
                    </div>
                    
                    <div class="footer-links-group">
                        <h4 class="footer-heading">Contact Info</h4>
                        <ul class="footer-links">
                            <li><i class="fas fa-map-marker-alt"></i> Rwanda Polytechnic Musanze College Student Union</li>
                            <li><i class="fas fa-phone"></i> +250 788 123 456</li>
                            <li><i class="fas fa-envelope"></i>rpmusanzesu@gmail.com</li>
                            <li><i class="fas fa-clock"></i> Mon - Fri: 8:00 - 17:00</li>
                        </ul>
                    </div>
                </div>
                
                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> Rwanda Polytechnic Musanze - RPSU Isonga Management System. All rights reserved.</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Loading screen functionality
        document.addEventListener('DOMContentLoaded', function() {
            const loadingScreen = document.getElementById('loadingScreen');
            const mainContent = document.getElementById('mainContent');
            
            // Show loading screen for 3 seconds
            setTimeout(function() {
                loadingScreen.classList.add('fade-out');
                mainContent.classList.add('visible');
                
                setTimeout(function() {
                    loadingScreen.style.display = 'none';
                }, 700);
            }, 3000);
            
            const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            
            AOS.init({
                duration: 800,
                once: true,
                offset: 100,
                disable: prefersReducedMotion
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
            
            let ticking = false;
            window.addEventListener('scroll', function() {
                if (!ticking) {
                    window.requestAnimationFrame(function() {
                        updateHeader();
                        ticking = false;
                    });
                    ticking = true;
                }
            });
            
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
            
            document.addEventListener('click', function(event) {
                if (mobileMenu.classList.contains('active') && 
                    !mobileMenu.contains(event.target) && 
                    !mobileMenuBtn.contains(event.target)) {
                    toggleMobileMenu();
                }
            });
            
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    toggleMobileMenu();
                }
            });

            // Fix for images on mobile
            const allImages = document.querySelectorAll('img');
            allImages.forEach(img => {
                img.addEventListener('error', function() {
                    const container = this.closest('.member-image-container, .link-card-image, .highlight-image');
                    if (container && !container.querySelector('.image-fallback')) {
                        const placeholder = document.createElement('div');
                        placeholder.className = 'member-image-placeholder image-fallback';
                        placeholder.innerHTML = '<i class="fas fa-user"></i>';
                        this.style.display = 'none';
                        container.appendChild(placeholder);
                    }
                });
                
                if (img.complete && img.naturalWidth === 0) {
                    img.dispatchEvent(new Event('error'));
                }
            });
        });
    </script>
</body>
</html>
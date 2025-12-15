<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

initSession();

if (isset($_SESSION['user_id'])) {
    redirectTo('modules/dashboard/index.php');
}

$error = '';
$success = '';
$company_name = '';
$tenant_name = '';
$first_name = '';
$last_name = '';
$email = '';
$mailing_list = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = sanitizeInput($_POST['company_name'] ?? '');
    $tenant_name = strtolower(sanitizeInput($_POST['tenant_name'] ?? ''));
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $business_type = sanitizeInput($_POST['business_type'] ?? 'Electronics Retail');
    $country = sanitizeInput($_POST['country'] ?? 'Zimbabwe');
    $currency = sanitizeInput($_POST['currency'] ?? 'USD');
    $mailing_list = isset($_POST['mailing_list']) ? 1 : 0;
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (empty($company_name) || empty($tenant_name) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/^[a-z0-9]+$/', $tenant_name)) {
        $error = 'Tenant name can only contain lowercase letters and numbers.';
    } elseif (strlen($tenant_name) < 3 || strlen($tenant_name) > 20) {
        $error = 'Tenant name must be between 3 and 20 characters.';
    } elseif (checkTenantExists($tenant_name)) {
        $error = 'This tenant name is already taken. Please choose a different one.';
    } else {
        $result = createTenantAndUser([
            'company_name' => $company_name,
            'tenant_name' => $tenant_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password' => $password,
            'business_type' => $business_type,
            'country' => $country,
            'currency' => $currency
        ]);
        
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Create Account - <?= SYSTEM_NAME ?></title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_URL ?>images/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --secondary-blue: #3b82f6;
            --light-blue: #dbeafe;
            --accent-blue: #60a5fa;
            --dark-navy: #1e40af;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --white: #ffffff;
            --light-gray: #f8fafc;
            --border-color: #e5e7eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--light-gray);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .main-container {
            min-height: 100vh;
            display: flex;
        }
        
        .left-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: white;
            overflow-y: auto;
        }
        
        .right-panel {
            flex: 1;
            background: var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .animated-pattern {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.1;
            background-image: 
                radial-gradient(circle at 25% 25%, var(--accent-blue) 2px, transparent 2px),
                radial-gradient(circle at 75% 75%, var(--secondary-blue) 2px, transparent 2px);
            background-size: 60px 60px;
            animation: patternMove 20s linear infinite;
        }
        
        @keyframes patternMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(60px, 60px); }
        }
        
        .register-form-container {
            width: 100%;
            max-width: 700px;
            animation: slideInLeft 0.8s ease-out;
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .brand-section {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 0.75rem;
            background: var(--primary-blue);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.2);
            transition: transform 0.3s ease;
        }
        
        .logo:hover {
            transform: translateY(-3px) scale(1.05);
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 8px;
        }
        
        .brand-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-blue);
            margin-bottom: 0.2rem;
            letter-spacing: -0.02em;
        }
        
        .brand-subtitle {
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .form-header {
            margin-bottom: 1rem;
        }
        
        .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .form-subtitle {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .form-subtitle a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .form-subtitle a:hover {
            color: var(--secondary-blue);
        }
        
        .section-divider {
            margin: 1rem 0 0.5rem 0;
            padding-bottom: 0.3rem;
            border-bottom: 2px solid var(--light-blue);
            position: relative;
        }
        
        .section-divider::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: var(--primary-blue);
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-blue);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .form-group {
            margin-bottom: 0.75rem;
            position: relative;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .input-group {
            position: relative;
        }
        
        .form-control {
            height: 3rem;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 0 1rem 0 2.75rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: white;
            font-weight: 500;
        }
        
        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 58, 138, 0.1);
            transform: translateY(-1px);
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1rem;
            z-index: 5;
            transition: color 0.3s ease;
            pointer-events: none;
        }
        
        .input-group:focus-within .input-icon {
            color: var(--primary-blue);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1rem;
            z-index: 5;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary-blue);
        }
        
        .password-strength-meter {
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .password-strength-fill {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak {
            width: 25%;
            background: var(--danger);
        }
        
        .strength-fair {
            width: 50%;
            background: var(--warning);
        }
        
        .strength-good {
            width: 75%;
            background: var(--secondary-blue);
        }
        
        .strength-strong {
            width: 100%;
            background: var(--success);
        }
        
        .password-requirements {
            font-size: 0.7rem;
            margin-top: 0.3rem;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.1rem;
            font-size: 0.7rem;
        }
        
        .requirement-item.valid {
            color: var(--success);
        }
        
        .requirement-item.valid i {
            color: var(--success) !important;
        }
        
        .tenant-validation {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            font-weight: 600;
        }
        
        .tenant-available {
            color: var(--success);
        }
        
        .tenant-taken {
            color: var(--danger);
        }
        
        .tenant-suggestions {
            margin-top: 0.5rem;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            max-height: 150px;
            overflow-y: auto;
            z-index: 1000;
            position: absolute;
            width: 100%;
        }
        
        .suggestion-item {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.8rem;
            color: var(--text-dark);
            transition: background-color 0.2s ease;
        }
        
        .suggestion-item:hover {
            background-color: var(--light-blue);
            color: var(--primary-blue);
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
        }
        
        .suggestion-item .badge {
            background: var(--primary-blue);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            margin-left: 0.5rem;
        }
        
        .form-check {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-check-input {
            margin-top: 0.125rem;
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-blue);
        }
        
        .form-check-label {
            line-height: 1.4;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .form-check-label a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        
        .form-check-label a:hover {
            text-decoration: underline;
        }
        
        .btn-primary {
            width: 100%;
            height: 3rem;
            background: var(--primary-blue);
            border: 2px solid var(--primary-blue);
            border-radius: 10px;
            color: white;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            transition: left 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--dark-navy);
            border-color: var(--dark-navy);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 58, 138, 0.3);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-outline {
            width: 100%;
            height: 3rem;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: white;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-outline:hover {
            border-color: var(--primary-blue);
            background: var(--light-blue);
            color: var(--primary-blue);
            transform: translateY(-1px);
        }
        
        .right-content {
            position: relative;
            z-index: 10;
            color: white;
            max-width: 480px;
            text-align: center;
            animation: slideInRight 0.8s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .feature-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .feature-text {
            font-size: 0.9rem;
            line-height: 1.6;
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }
        
        .learn-more-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.7rem 1.4rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .learn-more-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
            transform: translateY(-2px);
        }
        
        .carousel-section {
            margin-top: 2.5rem;
        }
        
        .carousel-content {
            min-height: 180px;
        }
        
        .carousel-item {
            display: none;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        .carousel-item.active {
            display: block;
            opacity: 1;
        }
        
        .carousel-title {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .carousel-description {
            font-size: 0.95rem;
            line-height: 1.6;
            opacity: 0.9;
        }
        
        .carousel-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .carousel-nav {
            width: 36px;
            height: 36px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.1rem;
        }
        
        .carousel-nav:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }
        
        .carousel-dots {
            display: flex;
            gap: 0.5rem;
        }
        
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .dot.active {
            background: white;
            transform: scale(1.2);
        }
        
        .alert-modern {
            border: none;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #10b981;
        }
        
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }
            
            .right-panel {
                display: none !important;
            }
            
            .left-panel {
                flex: 1;
                min-height: 100vh;
                padding: 1.5rem 1rem;
                background: var(--light-gray);
            }
            
            .register-form-container {
                max-width: 100%;
                background: white;
                border-radius: 20px;
                padding: 1.5rem 1.25rem;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            }
        }
        
        @media (max-width: 480px) {
            .left-panel {
                padding: 1rem 0.75rem;
            }
            
            .register-form-container {
                padding: 1.25rem 1rem;
                border-radius: 15px;
            }
            
            .form-control {
                height: 3rem;
                padding: 0 0.75rem 0 2.5rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="left-panel">
            <div class="register-form-container">
                <div class="brand-section">
                    <div class="logo">
                        <img src="<?= ASSETS_URL ?>images/logo.png" alt="ELECTROX" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: white; font-size: 1.5rem; font-weight: 700;">E</div>
                    </div>
                    <p class="brand-subtitle">Stock Management & POS System</p>
                </div>

                <div class="form-header">
                    <h2 class="form-title">Create your account</h2>
                    <p class="form-subtitle">Already have an account? <a href="login.php">Sign in</a></p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert-modern alert-danger">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <?= escapeHtml($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert-modern alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        <?= escapeHtml($success) ?>
                        <div class="mt-3">
                            <h6 class="fw-bold mb-2">What happens next?</h6>
                            <ul class="list-unstyled mb-0">
                                <li class="mb-1"><i class="bi bi-clock me-2 text-primary"></i>Your registration is being reviewed by our team</li>
                                <li class="mb-1"><i class="bi bi-envelope me-2 text-primary"></i>You'll receive an email notification once approved</li>
                                <li class="mb-1"><i class="bi bi-shield-check me-2 text-primary"></i>Your tenant will be activated and ready to use</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn-primary d-inline-flex align-items-center justify-content-center" style="width: auto; padding: 0.75rem 2rem;">
                            <i class="bi bi-arrow-left me-2"></i>Back to Login
                        </a>
                    </div>
                    
                    <style>
                        .right-panel {
                            display: none !important;
                        }
                        .left-panel {
                            flex: 1;
                            width: 100%;
                        }
                        .register-form-container {
                            max-width: 600px;
                            margin: 0 auto;
                        }
                    </style>
                <?php else: ?>
                    
                    <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>" id="registerForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                        <div class="section-divider">
                            <h3 class="section-title">Company Information</h3>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="company_name">Company Name *</label>
                                    <div class="input-group">
                                        <i class="bi bi-building-fill input-icon"></i>
                                        <input type="text" class="form-control" id="company_name" name="company_name" placeholder="Enter company name" required value="<?= escapeHtml($company_name) ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="tenant_name">Tenant Name *</label>
                                    <div class="input-group" style="position: relative;">
                                        <i class="bi bi-globe input-icon"></i>
                                        <input type="text" class="form-control" id="tenant_name" name="tenant_name" placeholder="mycompany123" required value="<?= escapeHtml($tenant_name) ?>" pattern="[a-z0-9]+" title="Only lowercase letters and numbers">
                                        <div class="tenant-validation" id="tenantValidation"></div>
                                        <div class="tenant-suggestions" id="tenantSuggestions" style="display: none;"></div>
                                    </div>
                                    <div class="form-text" style="font-size: 0.7rem; margin-top: 0.25rem;">One word workspace ID (lowercase & numbers only)</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="section-divider">
                            <h3 class="section-title">Account Information</h3>
                        </div>
                    
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="first_name">First Name *</label>
                                    <div class="input-group">
                                        <i class="bi bi-person-fill input-icon"></i>
                                        <input type="text" class="form-control" id="first_name" name="first_name" placeholder="John" required value="<?= escapeHtml($first_name) ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="last_name">Last Name *</label>
                                    <div class="input-group">
                                        <i class="bi bi-person-fill input-icon"></i>
                                        <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Doe" required value="<?= escapeHtml($last_name) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                        <div class="form-group">
                            <label class="form-label" for="email">Email Address *</label>
                            <div class="input-group">
                                <i class="bi bi-envelope-fill input-icon"></i>
                                <input type="email" class="form-control" id="email" name="email" placeholder="example@gmail.com" required value="<?= escapeHtml($email) ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="password">Password *</label>
                                    <div class="input-group">
                                        <i class="bi bi-lock-fill input-icon"></i>
                                        <input type="password" class="form-control" id="password" name="password" placeholder="Create password" required>
                                        <button type="button" class="password-toggle" id="togglePassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength-meter">
                                        <div class="password-strength-fill" id="passwordStrength"></div>
                                    </div>
                                    <div class="password-requirements" id="passwordRequirements" style="display: none;">
                                        <div class="requirement-item" data-requirement="length">
                                            <i class="bi bi-x-circle text-danger"></i> At least 8 characters
                                        </div>
                                        <div class="requirement-item" data-requirement="uppercase">
                                            <i class="bi bi-x-circle text-danger"></i> One uppercase letter
                                        </div>
                                        <div class="requirement-item" data-requirement="number">
                                            <i class="bi bi-x-circle text-danger"></i> One number
                                        </div>
                                        <div class="requirement-item" data-requirement="special">
                                            <i class="bi bi-x-circle text-danger"></i> One special character
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="confirm_password">Confirm Password *</label>
                                    <div class="input-group">
                                        <i class="bi bi-shield-lock-fill input-icon"></i>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
                                        <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div id="passwordMatch" class="form-text"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="mailing_list" name="mailing_list" <?= $mailing_list ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mailing_list">
                                Subscribe to our mailing list for product updates and educational content
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="terms_agree" name="terms_agree" required>
                            <label class="form-check-label" for="terms_agree">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a> *
                            </label>
                        </div>
                        
                        <button type="submit" class="btn-primary">
                            <i class="bi bi-person-plus me-2"></i>Create Account
                        </button>
                    
                        <a href="login.php" class="btn-outline">
                            <i class="bi bi-arrow-left"></i>Already have an account? Sign in
                        </a>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="right-panel">
            <div class="animated-pattern"></div>
            
            <div class="right-content">
                <div class="feature-card">
                    <h3 class="feature-title">Start Your Business Journey</h3>
                    <p class="feature-text">
                        Join thousands of electronics retailers that trust ELECTROX-POS to manage their inventory, process sales, and grow their business with comprehensive POS features and real-time analytics.
                    </p>
                    <a href="index.php" class="learn-more-btn">Learn more</a>
                </div>

                <div class="carousel-section">
                    <div class="carousel-content">
                        <div class="carousel-item active">
                            <h2 class="carousel-title">Welcome to ELECTROX-POS</h2>
                            <p class="carousel-description">
                                Transform your retail operations with our comprehensive platform designed for modern electronics businesses seeking operational excellence and growth.
                            </p>
                        </div>
                        
                        <div class="carousel-item">
                            <h2 class="carousel-title">AI-Powered Insights</h2>
                            <p class="carousel-description">
                                Leverage AI technology for trade-in valuations, stock predictions, and sales analytics to make informed decisions and maximize profitability.
                            </p>
                        </div>
                        
                        <div class="carousel-item">
                            <h2 class="carousel-title">Complete POS Solution</h2>
                            <p class="carousel-description">
                                Built with multi-branch support, real-time inventory tracking, fiscal integration, and comprehensive reporting to ensure your business runs smoothly.
                            </p>
                        </div>
                    </div>

                    <div class="carousel-controls">
                        <button class="carousel-nav" id="prevSlide">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        
                        <div class="carousel-dots">
                            <span class="dot active" data-slide="0"></span>
                            <span class="dot" data-slide="1"></span>
                            <span class="dot" data-slide="2"></span>
                        </div>
                        
                        <button class="carousel-nav" id="nextSlide">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Terms Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 15px;">
                <div class="modal-header" style="border-bottom: 2px solid var(--light-blue);">
                    <h5 class="modal-title" id="termsModalLabel" style="color: var(--primary-blue); font-weight: 700;">Terms & Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="line-height: 1.6;">
                    <h4 style="color: var(--primary-blue); margin-top: 0;">1. Introduction</h4>
                    <p>These Terms of Service govern your use of ELECTROX-POS, a comprehensive stock management, invoicing, and point-of-sale platform. By using ELECTROX-POS, you agree to these terms.</p>
                    
                    <h4 style="color: var(--primary-blue);">2. Definitions</h4>
                    <p><strong>"Service"</strong> refers to the ELECTROX-POS platform.</p>
                    <p><strong>"We", "us", and "our"</strong> refer to ELECTROX-POS.</p>
                    <p><strong>"You"</strong> refers to the individual or organization accessing or using the Service.</p>
                    
                    <h4 style="color: var(--primary-blue);">3. Account Registration</h4>
                    <p>You are responsible for maintaining the security of your account credentials and for all activities that occur under your account. You must provide accurate and complete information when registering.</p>
                    
                    <h4 style="color: var(--primary-blue);">4. Acceptable Use</h4>
                    <p>You agree not to use the Service to:</p>
                    <ul>
                        <li>Violate any applicable laws or regulations</li>
                        <li>Infringe on intellectual property rights</li>
                        <li>Transmit malicious code or engage in unauthorized access attempts</li>
                        <li>Conduct activities that interfere with the proper functioning of the Service</li>
                    </ul>
                    
                    <h4 style="color: var(--primary-blue);">5. Privacy & Data Protection</h4>
                    <p>We are committed to protecting your data with enterprise-grade security measures and compliance with applicable data protection regulations.</p>
                    
                    <h4 style="color: var(--primary-blue);">6. Contact Us</h4>
                    <p>If you have any questions about these Terms, please contact us at info@electrox.co.zw.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius: 10px;">I Understand</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Password toggle functionality
        $('#togglePassword').on('click', function() {
            const passwordField = $('#password');
            const passwordFieldType = passwordField.attr('type');
            const icon = $(this).find('i');
            
            if (passwordFieldType === 'password') {
                passwordField.attr('type', 'text');
                icon.removeClass('bi-eye').addClass('bi-eye-slash');
            } else {
                passwordField.attr('type', 'password');
                icon.removeClass('bi-eye-slash').addClass('bi-eye');
            }
        });
        
        $('#toggleConfirmPassword').on('click', function() {
            const confirmPasswordField = $('#confirm_password');
            const confirmPasswordFieldType = confirmPasswordField.attr('type');
            const icon = $(this).find('i');
            
            if (confirmPasswordFieldType === 'password') {
                confirmPasswordField.attr('type', 'text');
                icon.removeClass('bi-eye').addClass('bi-eye-slash');
            } else {
                confirmPasswordField.attr('type', 'password');
                icon.removeClass('bi-eye-slash').addClass('bi-eye');
            }
        });
        
        // Password strength meter and validation
        $('#password').on('input', function() {
            const password = $(this).val();
            const requirements = $('#passwordRequirements');
            const requirementItems = requirements.find('.requirement-item');
            
            if (password.length > 0) {
                requirements.show();
            } else {
                requirements.hide();
            }
            
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            requirementItems.each(function() {
                const requirement = $(this).data('requirement');
                const isValid = checks[requirement];
                const icon = $(this).find('i');
                
                if (isValid) {
                    $(this).addClass('valid');
                    icon.removeClass('bi-x-circle text-danger').addClass('bi-check-circle text-success');
                } else {
                    $(this).removeClass('valid');
                    icon.removeClass('bi-check-circle text-success').addClass('bi-x-circle text-danger');
                }
            });
            
            const strength = Object.values(checks).filter(Boolean).length;
            const passwordStrength = $('#passwordStrength');
            passwordStrength.removeClass('strength-weak strength-fair strength-good strength-strong');
            
            if (password.length === 0) {
                passwordStrength.css('width', '0%');
            } else if (strength === 1) {
                passwordStrength.addClass('strength-weak');
            } else if (strength === 2) {
                passwordStrength.addClass('strength-fair');
            } else if (strength === 3) {
                passwordStrength.addClass('strength-good');
            } else if (strength === 4) {
                passwordStrength.addClass('strength-strong');
            }
        });
        
        // Password match validation
        $('#confirm_password').on('input', function() {
            const password = $('#password').val();
            const confirmPassword = $(this).val();
            const matchDiv = $('#passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchDiv.text('').removeClass('text-success text-danger');
            } else if (password === confirmPassword) {
                matchDiv.html('<i class="bi bi-check-circle"></i> Passwords match').addClass('text-success').removeClass('text-danger');
            } else {
                matchDiv.html('<i class="bi bi-x-circle"></i> Passwords do not match').addClass('text-danger').removeClass('text-success');
            }
        });
        
        // Tenant name validation
        let tenantCheckTimeout;
        $('#tenant_name').on('input', function() {
            const tenantName = $(this).val().toLowerCase().replace(/[^a-z0-9]/g, '');
            $(this).val(tenantName);
            
            clearTimeout(tenantCheckTimeout);
            
            if (tenantName.length >= 3) {
                tenantCheckTimeout = setTimeout(function() {
                    checkTenantAvailability(tenantName);
                }, 500);
            } else {
                $('#tenantValidation').text('').removeClass('tenant-available tenant-taken');
                $('#tenantSuggestions').hide().empty();
            }
        });
        
        // Auto-generate tenant name from company name
        $('#company_name').on('input', function() {
            const companyName = $(this).val();
            const tenantName = companyName.toLowerCase().replace(/[^a-z0-9]/g, '').substring(0, 20);
            if (tenantName.length >= 3 && $('#tenant_name').val() === '') {
                $('#tenant_name').val(tenantName);
                checkTenantAvailability(tenantName);
            }
        });
        
        function checkTenantAvailability(tenantName) {
            const validation = $('#tenantValidation');
            const suggestions = $('#tenantSuggestions');
            
            validation.html('<i class="spinner-border spinner-border-sm me-1"></i>Checking...');
            suggestions.hide().empty();
            
            $.ajax({
                url: 'ajax/check_tenant.php',
                method: 'POST',
                data: {
                    tenant_name: tenantName
                },
                dataType: 'json',
                success: function(response) {
                    if (response.available) {
                        validation.html('<i class="bi bi-check-circle"></i> Available').removeClass('tenant-taken').addClass('tenant-available');
                        $('#tenant_name').removeClass('is-invalid').addClass('is-valid');
                    } else {
                        validation.html('<i class="bi bi-x-circle"></i> Already taken').removeClass('tenant-available').addClass('tenant-taken');
                        $('#tenant_name').removeClass('is-valid').addClass('is-invalid');
                        
                        if (response.suggestions && response.suggestions.length > 0) {
                            displaySuggestions(response.suggestions);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    validation.html('<i class="bi bi-x-circle"></i> Error checking').removeClass('tenant-available').addClass('tenant-taken');
                    $('#tenant_name').removeClass('is-valid').addClass('is-invalid');
                }
            });
        }
        
        function displaySuggestions(suggestions) {
            const suggestionsContainer = $('#tenantSuggestions');
            suggestionsContainer.empty();
            
            suggestions.forEach(function(suggestion) {
                const suggestionItem = $('<div class="suggestion-item" data-tenant="' + suggestion + '">')
                    .html(suggestion + ' <span class="badge">Available</span>');
                
                suggestionItem.on('click', function() {
                    $('#tenant_name').val(suggestion);
                    $('#tenantValidation').html('<i class="bi bi-check-circle"></i> Available').removeClass('tenant-taken').addClass('tenant-available');
                    $('#tenant_name').removeClass('is-invalid').addClass('is-valid');
                    suggestionsContainer.hide();
                    checkTenantAvailability(suggestion);
                });
                
                suggestionsContainer.append(suggestionItem);
            });
            
            suggestionsContainer.show();
        }
        
        // Hide suggestions when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#tenant_name, #tenantSuggestions').length) {
                $('#tenantSuggestions').hide();
            }
        });
        
        // Form validation
        $('#registerForm').on('submit', function(e) {
            let isValid = true;
            
            $('.form-control').removeClass('is-invalid is-valid');
            
            const password = $('#password').val();
            let strength = 0;
            
            if (password.length >= 8) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            if (strength < 3) {
                $('#password').addClass('is-invalid');
                isValid = false;
            }
            
            const confirmPassword = $('#confirm_password').val();
            if (password !== confirmPassword) {
                $('#confirm_password').addClass('is-invalid');
                isValid = false;
            }
            
            if (!$('#terms_agree').is(':checked')) {
                isValid = false;
                Swal.fire({
                    icon: 'warning',
                    title: 'Terms Required',
                    text: 'You must agree to the Terms & Conditions.',
                    confirmButtonColor: '#1e3a8a'
                });
            }
            
            if (!isValid) {
                e.preventDefault();
                $('.is-invalid').first().focus();
            } else {
                const btn = $('.btn-primary');
                btn.html('<i class="spinner-border spinner-border-sm me-2"></i>Creating Account...');
                btn.prop('disabled', true);
            }
        });
        
        // Carousel functionality
        let currentSlide = 0;
        const slides = $('.carousel-item');
        const dots = $('.dot');
        const totalSlides = slides.length;
        
        function showSlide(index) {
            slides.removeClass('active');
            dots.removeClass('active');
            slides.eq(index).addClass('active');
            dots.eq(index).addClass('active');
            currentSlide = index;
        }
        
        function nextSlide() {
            const next = (currentSlide + 1) % totalSlides;
            showSlide(next);
        }
        
        function prevSlide() {
            const prev = (currentSlide - 1 + totalSlides) % totalSlides;
            showSlide(prev);
        }

        $('#nextSlide').on('click', nextSlide);
        $('#prevSlide').on('click', prevSlide);
        
        dots.on('click', function() {
            const slideIndex = $(this).data('slide');
            showSlide(slideIndex);
        });

        setInterval(nextSlide, 4000);
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

initSession();

if (isset($_SESSION['user_id'])) {
    redirectTo('modules/dashboard/index.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_name = trim($_POST['tenant_name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    $remember_me = isset($_POST['remember_me']) ? 1 : 0;
    
    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (empty($tenant_name)) {
        $error = 'Tenant name is required.';
    } elseif (!checkTenantExists($tenant_name)) {
        $error = 'Tenant does not exist. Please check your tenant name.';
    } elseif (!isTenantActive($tenant_name)) {
        $error = 'This tenant account is not active. Please contact support.';
    } else {
        setCurrentTenant($tenant_name);
        $auth = Auth::getInstance();
        $result = $auth->login($email, $password, $tenant_name);
        
        if ($result['success']) {
            if ($remember_me) {
                setcookie('remember_tenant', $tenant_name, time() + (86400 * 30), '/');
            }
            redirectTo('modules/dashboard/index.php');
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
    <title>Sign In - <?= SYSTEM_NAME ?></title>
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
            position: relative;
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
        
        .login-form-container {
            width: 100%;
            max-width: 500px;
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
            margin-bottom: 2.5rem;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: var(--primary-blue);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(30, 58, 138, 0.2);
            transition: transform 0.3s ease;
        }
        
        .logo:hover {
            transform: translateY(-5px) scale(1.05);
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 12px;
        }
        
        .brand-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }
        
        .brand-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .form-header {
            margin-bottom: 2rem;
        }
        
        .form-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .form-subtitle {
            color: var(--text-muted);
            font-size: 0.9rem;
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
        
        .form-group {
            margin-bottom: 1.5rem;
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
            height: 3.5rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 0 1rem 0 3rem;
            font-size: 0.95rem;
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
            font-size: 1.1rem;
            z-index: 5;
            transition: color 0.3s ease;
            pointer-events: none;
        }
        
        .input-group:focus-within .input-icon {
            color: var(--primary-blue);
        }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            font-size: 0.875rem;
        }
        
        .custom-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .custom-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-blue);
        }
        
        .forgot-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .forgot-link:hover {
            color: var(--secondary-blue);
        }
        
        .btn-primary {
            width: 100%;
            height: 3.5rem;
            background: var(--primary-blue);
            border: 2px solid var(--primary-blue);
            border-radius: 12px;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
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
        
        .signup-link {
            text-align: center;
            margin-top: 2rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .signup-link a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        
        .signup-link a:hover {
            text-decoration: underline;
        }
        
        .right-content {
            position: relative;
            z-index: 10;
            color: white;
            max-width: 500px;
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
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .feature-text {
            font-size: 0.95rem;
            line-height: 1.6;
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }
        
        .learn-more-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.75rem 1.5rem;
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
        
        .alert-modern {
            border: none;
            border-radius: 12px;
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
        
        @media (max-width: 992px) {
            .right-panel {
                display: none;
            }
            .left-panel {
                flex: 1;
            }
        }
        
        @media (max-width: 480px) {
            .left-panel {
                padding: 1rem;
            }
            .form-title {
                font-size: 1.5rem;
            }
            .brand-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="left-panel">
            <div class="login-form-container">
                <div class="brand-section">
                    <div class="logo">
                        <img src="<?= ASSETS_URL ?>images/logo.png" alt="ELECTROX" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: white; font-size: 2rem; font-weight: 700;">E</div>
                    </div>
                    <div class="brand-title">ELECTROX</div>
                    <div class="brand-subtitle">Stock Management & POS System</div>
                </div>
                
                <div class="form-header">
                    <h1 class="form-title">Sign in</h1>
                    <p class="form-subtitle">
                        New to ELECTROX? <a href="register.php">Create your account</a>
                    </p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert-modern alert-danger">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= escapeHtml($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert-modern alert-success">
                        <i class="bi bi-check-circle"></i>
                        <?= escapeHtml($success) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Tenant Name</label>
                        <div class="input-group">
                            <i class="bi bi-building input-icon"></i>
                            <input type="text" class="form-control" name="tenant_name" required autofocus placeholder="Enter your tenant name" value="<?= escapeHtml($_COOKIE['remember_tenant'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <i class="bi bi-envelope input-icon"></i>
                            <input type="email" class="form-control" name="email" required placeholder="Enter your email">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password" class="form-control" name="password" required placeholder="Enter your password">
                        </div>
                    </div>
                    
                    <div class="form-options">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="remember_me" value="1">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="forgot-link">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        Sign In
                    </button>
                </form>
                
                <div class="signup-link">
                    Don't have an account? <a href="register.php">Sign up for free</a>
                </div>
            </div>
        </div>
        
        <div class="right-panel">
            <div class="animated-pattern"></div>
            <div class="right-content">
                <div class="feature-card">
                    <h2 class="feature-title">Streamline Your Business</h2>
                    <p class="feature-text">
                        Manage your electronics retail business efficiently with comprehensive stock management, invoicing, and point-of-sale features. Track inventory in real-time and make informed decisions.
                    </p>
                    <a href="#" class="learn-more-btn">Learn more</a>
                </div>
                
                <div class="feature-card">
                    <h2 class="feature-title">Real-time Inventory Control</h2>
                    <p class="feature-text">
                        Advanced inventory management with multi-branch support, stock transfers, and automated alerts. Keep track of every product across all your locations with ease.
                    </p>
                    <a href="#" class="learn-more-btn">Learn more</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

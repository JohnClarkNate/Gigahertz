<?php
require 'db.php';
session_start();

// Define constants, including the lockout duration for *automatic* locks
const LOGIN_ATTEMPT_FILE = __DIR__ . '/login_attempts.json';
const LOGIN_ATTEMPT_LIMIT = 5;
const LOGIN_NOTIFICATION_SENDER = 'no-reply@erp.local';
const LOGIN_NOTIFICATION_DEFAULT_ADMIN_EMAIL = 'admin@erp.local';
const LOGIN_LOCKOUT_MINUTES = 15; // Duration for *automatic* failed-attempt locks

// Basic file-backed login throttle to slow brute-force attempts.
function loadLoginAttempts(string $filePath): array
{
    if (!file_exists($filePath)) {
        return [];
    }

    $contents = file_get_contents($filePath);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function persistLoginAttempts(string $filePath, array $attempts): void
{
    $encoded = json_encode($attempts, JSON_PRETTY_PRINT);
    if ($encoded === false) {
        return;
    }

    file_put_contents($filePath, $encoded, LOCK_EX);
}

function minutesUntil(int $timestamp): int
{
    $seconds = max(0, $timestamp - time());
    return (int)ceil($seconds / 60);
}

function sendLockoutEmail(?string $recipientEmail, string $username, ?string $companyName, int $lockedUntil): void
{
    $fallback = LOGIN_NOTIFICATION_DEFAULT_ADMIN_EMAIL;
    $recipient = null;

    if ($recipientEmail && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $recipient = $recipientEmail;
    } elseif ($fallback && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
        $recipient = $fallback;
    }

    if (!$recipient) {
        return;
    }

    $company = $companyName ?: 'your ERP instance';
    $lockedAt = date('Y-m-d H:i');
    $unlockAt = date('Y-m-d H:i', $lockedUntil);
    $subject = "[ERP System] Account locked for {$username}";
    $message = "Hello,\n\n"
        . "This is an automated security notice from {$company}. The account '{$username}' was locked after multiple failed login attempts on {$lockedAt}.\n\n"
        . "The account will automatically unlock at {$unlockAt}. If immediate access is required, sign in using an administrator account (example credentials: username 'jiji', password '123') and remove the lock from Manage Account → Locked Accounts, or update the affected user's password.\n\n"
        . "If you did not initiate these attempts, please review recent activity and rotate the user's password.\n\n"
        . "Thank you,\nERP Security Monitor";

    $headers = 'From: ' . LOGIN_NOTIFICATION_SENDER . "\r\n"
        . 'Reply-To: ' . LOGIN_NOTIFICATION_SENDER . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8';

    @mail($recipient, $subject, $message, $headers);
}

if (!function_exists('logActivity')) {
    function logActivity(PDO $pdo, int $companyId, ?int $userId, string $userRole, string $module, string $action, ?string $description = null, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (company_id, user_id, user_role, module, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $userId,
            $userRole,
            $module,
            $action,
            $description,
            $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)
        ]);
    }
}


// Admin/Head login
$isAdminLoginRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['admin_username'], $_POST['admin_password']);

if ($isAdminLoginRequest) {
    $username = trim((string)($_POST['admin_username'] ?? ''));
    $password = (string)($_POST['admin_password'] ?? '');

            if ($username === '' || $password === '') {
                $login_error = 'Please provide both username and password.';
            }

            if (!isset($login_error)) {
                $attemptKey = strtolower($username); // Use lowercase for consistency
                $attempts = loadLoginAttempts(LOGIN_ATTEMPT_FILE);
                $attemptRecord = $attempts[$attemptKey] ?? null;
                $now = time();

                // Check if there's a lock record (either manual or automatic) and if it's still active
                if ($attemptRecord && isset($attemptRecord['locked_until'])) {
                    // Check if the lock is still active based on the stored timestamp
                    if ($now < $attemptRecord['locked_until']) {
                        // Determine the reason for the lock based on the 'locked_manually' flag
                        if (isset($attemptRecord['locked_manually']) && $attemptRecord['locked_manually'] === true) {
                            // Manual lock via admin panel; treat as indefinite until admin unlocks
                            $login_error = "Your account has been locked by an administrator. Please contact support.";
                        } else {
                            // Automatic lock due to failed attempts
                            $minutesRemaining = minutesUntil((int)$attemptRecord['locked_until']);
                            $login_error = "Too many failed attempts. Try again in {$minutesRemaining} minute(s).";
                        }
                    } else {
                        // Automatic lock expired; clean up record unless it was manual
                        if (!isset($attemptRecord['locked_manually']) || $attemptRecord['locked_manually'] !== true) {
                            unset($attempts[$attemptKey]);
                            persistLoginAttempts(LOGIN_ATTEMPT_FILE, $attempts);
                            $attemptRecord = null;
                        }
                    }
                }

                // Manual lock check after potential cleanup
                if ($attemptRecord && isset($attemptRecord['locked_manually']) && $attemptRecord['locked_manually'] === true) {
                    $login_error = "Your account has been locked by an administrator. Please contact support.";
                } elseif (!isset($login_error)) {
                    // Proceed with the database lookup and password verification
                    $stmt = $pdo->prepare("SELECT users.*, companies.name AS company_name, companies.code AS company_code, companies.email AS company_email FROM users 
                                       JOIN companies ON users.company_id = companies.id 
                                       WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password'])) {
                        // Login successful, clear any *automatic* failed attempt records for this user
                        if (isset($attempts[$attemptKey]) && (!isset($attempts[$attemptKey]['locked_manually']) || $attempts[$attemptKey]['locked_manually'] !== true)) {
                            unset($attempts[$attemptKey]);
                            persistLoginAttempts(LOGIN_ATTEMPT_FILE, $attempts);
                        }

                        $_SESSION['user'] = $user['username'];
                        $_SESSION['company'] = $user['company_name'];
                        $_SESSION['company_id'] = $user['company_id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['company_code'] = $user['company_code'];

                        logActivity(
                            $pdo,
                            (int)$user['company_id'],
                            (int)$user['id'],
                            $user['role'] ?? 'unknown',
                            'login',
                            'login_success',
                            'User authenticated via admin/head portal.'
                        );

                        switch ($user['role']) {
                            case 'admin':
                                header("Location: dashboard_admin.php");
                                break;
                            case 'staff':
                                header("Location: pos.php?route=system");
                                break;
                            case 'head_hr':
                                header("Location: dashboard_hr.php");
                                break;
                            case 'head_finance':
                                header("Location: dashboard_finance.php");
                                break;
                            case 'head_sales':
                                header("Location: dashboard_sales.php");
                                break;
                            case 'head_inventory':
                                header("Location: dashboard_inventory.php");
                                break;
                            case 'head_pos':
                                header("Location: pos.php?route=system");
                                exit();
                            default:
                                $login_error = "Unknown role.";
                                break;
                        }

                        if (!isset($login_error)) {
                            exit();
                        }
                    } else {
                        // Login failed, update the attempt record
                        $attemptRecord = $attemptRecord ?? ['attempts' => 0];
                        $attemptRecord['attempts'] = ($attemptRecord['attempts'] ?? 0) + 1;
                        $attemptRecord['last_attempt'] = $now;
                        unset($attemptRecord['locked_manually']);

                        if ($attemptRecord['attempts'] >= LOGIN_ATTEMPT_LIMIT) {
                            $attemptRecord['locked_until'] = $now + (LOGIN_LOCKOUT_MINUTES * 60);
                            $login_error = "Too many failed attempts. Account locked for " . LOGIN_LOCKOUT_MINUTES . " minute(s).";
                            $notificationEmail = $user['company_email'] ?? null;
                            $companyLabel = $user['company_name'] ?? null;
                            sendLockoutEmail($notificationEmail, $username, $companyLabel, $attemptRecord['locked_until']);
                        } else {
                            unset($attemptRecord['locked_until']);
                            $remaining = LOGIN_ATTEMPT_LIMIT - $attemptRecord['attempts'];
                            $attemptWord = $remaining === 1 ? 'attempt' : 'attempts';
                            $login_error = "Invalid admin/head credentials. {$remaining} {$attemptWord} remaining before lockout.";
                        }

                        $attempts[$attemptKey] = $attemptRecord;
                        persistLoginAttempts(LOGIN_ATTEMPT_FILE, $attempts);
                    }
                }
            }
        }
    ?>

    <!DOCTYPE html>
<html lang="en">
<head>
    <title>Login</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Apply saved theme immediately -->
    <script>
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css        ">
    <style>
        /* --- Reuse your existing CSS variables from dashboard_admin.php --- */
        :root {
            --bg-primary: #f0f4f8;
            --bg-secondary: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-modal: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --success-bg: #d1fae5;
            --danger: #ef4444;
            --danger-bg: #fee2e2;
            --warning: #f59e0b;
            --info: #0ea5e9;
            --sidebar-width: 240px;
            --transition: all 0.2s ease;
            --radius: 0.5rem;
            --radius-sm: 0.25rem;
            --radius-lg: 0.75rem;
        }
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: #334155;
            --border-light: #1e293b;
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            --shadow-modal: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #34d399;
            --success-bg: rgba(16, 185, 129, 0.2);
            --danger: #f87171;
            --danger-bg: rgba(239, 68, 68, 0.2);
            --warning: #fbbf24;
            --info: #7dd3fc;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, var(--bg-primary), var(--bg-secondary));
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            transition: var(--transition);
            overflow: hidden; /* Prevent scrollbars during animation */
        }
        .login-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .login-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 3rem;
            width: 100%;
            max-width: 480px;
            box-shadow: var(--shadow-card);
            position: relative;
            z-index: 2; /* Ensure card is above background elements */
            animation: fadeInUp 0.6s ease-out;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--primary), var(--info));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-fill-color: transparent;
        }
        .login-header p {
            color: var(--text-secondary);
            font-size: 1rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9375rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem; /* Add padding for icon */
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.9375rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: var(--transition);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.125rem;
        }
        .btn {
            width: 100%;
            padding: 0.875rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-hover));
            color: white;
        }
        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-hover), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
        .error-message {
            color: var(--danger);
            background: var(--danger-bg);
            padding: 0.875rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 500;
            border-left: 4px solid var(--danger);
            animation: shake 0.5s ease-in-out;
        }
        .theme-toggle {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-primary);
            font-size: 1.125rem;
            flex-shrink: 0;
            z-index: 10;
        }
        .theme-toggle:hover {
            background: var(--border-light);
            border-color: var(--primary);
            color: var(--primary);
        }
        /* --- Background Elements for Visual Interest --- */
        .bg-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            overflow: hidden;
        }
        .shape {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            opacity: 0.1;
            animation: float 15s infinite ease-in-out;
        }
        .shape:nth-child(1) {
            width: 300px;
            height: 300px;
            top: -100px;
            left: -100px;
        }
        .shape:nth-child(2) {
            width: 200px;
            height: 200px;
            bottom: -80px;
            right: 20%;
            animation-delay: -5s;
        }
        .shape:nth-child(3) {
            width: 150px;
            height: 150px;
            top: 40%;
            right: -50px;
            animation-delay: -10s;
        }
        /* --- Animations --- */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes float {
            0%, 100% {
                transform: translate(0, 0);
            }
            25% {
                transform: translate(-20px, -20px);
            }
            50% {
                transform: translate(20px, 20px);
            }
            75% {
                transform: translate(20px, -20px);
            }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        /* --- Responsive Adjustments --- */
        @media (max-width: 768px) {
            .login-card {
                margin: 1.5rem;
                padding: 2rem 1.5rem;
            }
            .theme-toggle {
                 top: 1rem;
                 right: 1rem;
            }
            .login-header h1 {
                 font-size: 1.75rem;
            }
            .bg-shapes {
                 display: none; /* Hide shapes on mobile for performance */
            }
        }
        /* --- Focus Styles --- */
        button:focus,
        input:focus,
        select:focus,
        textarea:focus,
        a:focus {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
<div class="bg-shapes">
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
</div>

<div class="theme-toggle" onclick="toggleTheme()" id="themeToggle">
    <i class="fas fa-moon"></i>
</div>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h2>Login </h2>
            <p>Sign in to your account</p>
        </div>

        <?php if (isset($login_error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($login_error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="admin_username"> Username</label>
                <div style="position: relative;">
                    <i class="fas fa-user form-icon"></i>
                    <input type="text" id="admin_username" name="admin_username" placeholder="Enter username" required>
                </div>
            </div>

            <div class="form-group">
                <label for="admin_password"> Password</label>
                <div style="position: relative;">
                    <i class="fas fa-key form-icon"></i>
                    <input type="password" id="admin_password" name="admin_password" placeholder="Enter password" required>
                </div>
            </div>

            <button type="submit" name="admin_login" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div class="login-footer"></div>
        </div>
    </div>
</div>

<script>
    // --- Theme Toggle Logic ---
    function toggleTheme() {
        const html = document.documentElement;
        const themeToggle = document.getElementById('themeToggle');
        const icon = themeToggle.querySelector('i');
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);

        icon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        // Add subtle animation
        icon.style.transform = 'rotate(180deg)';
        setTimeout(() => {
            icon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            icon.style.transform = 'rotate(0deg)';
        }, 150);
    }

    // Load saved theme on page load
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme') || 'light';
        const html = document.documentElement;
        const themeToggle = document.getElementById('themeToggle');
        const icon = themeToggle.querySelector('i');

        html.setAttribute('data-theme', savedTheme);
        icon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    });
</script>
</body>
</html>
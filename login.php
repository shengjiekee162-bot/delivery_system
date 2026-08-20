<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/functions.php';

$error = '';

// Redirect if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    } else {
        header("Location: rider/dashboard.php");
        exit;
    }
}

// Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            $db = get_db_connection();

            // Query user strictly by email
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.email, u.password_hash AS password, u.role, u.deleted_at 
                FROM users u 
                WHERE u.email = :email
                  AND u.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
                // Set session data
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['role']      = $user['role'];

                if ($user['role'] === 'rider') {
                    require_once __DIR__ . '/includes/rider_status.php';
                    mark_rider_online($db, (string)$user['id']);
                }

                // Log system activity if helper function exists
                if (function_exists('log_activity')) {
                    log_activity($user['id'], 'User Login');
                }

                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: rider/dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Parcel Delivery System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-card {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            width: 100%;
            max-width: 420px;
            padding: 32px 28px;
        }

        .login-card h1 {
            font-size: 1.45rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .login-card p.subtitle {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 24px;
        }

        .alert-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 12px;
            color: #94a3b8;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .btn-submit {
            width: 100%;
            background-color: #0284c7;
            color: #ffffff;
            border: none;
            padding: 11px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-submit:hover {
            background-color: #0369a1;
        }

        /* Warm editorial theme shared with the dashboard */
        body {
            background: radial-gradient(circle at 12% 12%, #d6f3e6 0, transparent 25rem), #f5f2ea;
            color: #173b37;
            font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .login-card {
            position: relative;
            overflow: hidden;
            max-width: 440px;
            padding: 38px 34px;
            border: 1px solid #e5e0d5;
            border-radius: 18px;
            background: rgba(255, 253, 248, .94);
            box-shadow: 0 24px 55px rgba(38, 68, 58, .13);
        }

        .login-card::before {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: #087e6b;
            content: "";
        }

        .login-card h1 { color: #173b37; font-family: "Space Grotesk", "Segoe UI", sans-serif; font-size: 1.7rem; letter-spacing: -.05em; }
        .login-card p.subtitle { color: #6c817d; }
        .form-group label { color: #38524d; }
        .form-control { border-color: #d9d6ca; border-radius: 9px; background: #fffefb; }
        .form-control:focus { border-color: #087e6b; box-shadow: 0 0 0 3px rgba(8, 126, 107, .13); }
        .input-wrapper i { color: #087e6b; }
        .btn-submit { border-radius: 9px; background: #087e6b; box-shadow: 0 6px 15px rgba(8, 126, 107, .18); }
        .btn-submit:hover { background: #056454; }

        .demo-accounts {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #e5e0d5;
        }
        .demo-accounts h2 {
            margin: 0 0 10px;
            color: #38524d;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .account-detail {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #d9e3df;
            border-radius: 9px;
            background: #f5faf7;
            color: #38524d;
            font-size: .81rem;
        }
        .account-detail + .account-detail { margin-top: 8px; }
        .account-role { font-weight: 700; color: #087e6b; white-space: nowrap; }
        .account-credentials { text-align: right; line-height: 1.5; }
    </style>
</head>
<body>

    <div class="login-card">
        <h1>Parcel Delivery System</h1>
        <p class="subtitle">Please enter your credentials to continue</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fa-solid fa-envelope"></i>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="Enter your email" 
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                        required 
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fa-solid fa-lock"></i>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Enter password" 
                        required
                    >
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Log In
            </button>
        </form>

        <section class="demo-accounts" aria-label="Test login accounts">
            <h2>Test Login Accounts</h2>
            <div class="account-detail">
                <span class="account-role"><i class="fa-solid fa-user-shield"></i> Admin</span>
                <span class="account-credentials">admin@courier.com<br>Password: admin123</span>
            </div>
            <div class="account-detail">
                <span class="account-role"><i class="fa-solid fa-motorcycle"></i> Rider</span>
                <span class="account-credentials">kee@gmail.com<br>Password: kee123</span>
            </div>
        </section>
    </div>

</body>
</html>

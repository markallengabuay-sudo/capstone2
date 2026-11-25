<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulwagan ng Katarungan - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php
    session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    ?>
    <style>
        :root {
            --primary-blue: #1a73e8;
            --danger-red: #d93025;
            --success-green: #0f9d58;
            --warning-yellow: #f4b400;
            --purple: #9c27b0;
            --dark-navy: #202124;
            --light-gray: #f8f9fa;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 1rem;
            height: 100vh;
            overflow: hidden;
        }

        .login-container {
            height: 100vh;
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0d47a1 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .login-content {
            text-align: center;
            max-width: 500px;
            width: 100%;
            padding: 20px;
            flex-shrink: 0;
        }

        .login-header-outside {
            text-align: center;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .institution-title-outside {
            font-size: 1.45rem;
            font-weight: bold;
            color: white;
            margin: 10px 0 5px;
            text-align: center;
        }

        .institution-subtitle-outside {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.9);
            margin: 0;
            text-align: center;
        }

        .system-name-outside {
            text-align: center;
            margin-top: 30px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.8);
            line-height: 1.4;
        }

        .login-panel {
            background: white;
            padding: 40px;
            border-radius: 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 400px;
            max-width: 90%;
            position: relative;
            left: 30px;
        }

        .login-title {
            text-align: center;
            margin-bottom: 30px;
            color: var(--dark-navy);
            font-size: 0.86rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            color: #555;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .form-control {
            padding-left: 45px;
            height: 50px;
            border: 1px solid #ddd;
            border-radius: 0;
            font-size: 1rem;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(26, 115, 232, 0.25);
        }

        .btn-login {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 0;
            font-size: 1rem;
            font-weight: 500;
            width: 100%;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-login:hover {
            background: #1557b0;
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: var(--primary-blue);
            text-decoration: none;
            font-size: 0.95rem;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        .error-message {
            background: #fee;
            color: var(--danger-red);
            padding: 10px;
            border-radius: 0;
            margin-bottom: 20px;
            display: none;
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }
            
            .left-side {
                flex: 0 0 40%;
            }
            
            .right-side {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-content">
            <div class="login-header-outside">
                <i class="fas fa-landmark" style="font-size: 60px; color: white;"></i>
                <div>
                    <h2 class="institution-title-outside">Bulwagan ng Katarungan</h2>
                    <p class="institution-subtitle-outside">Catbalogan City</p>
                </div>
            </div>
            
            <div class="login-panel">
                <h3 class="login-title">Login Panel</h3>
                
                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message" style="display: block;">
                        <?php 
                        switch($_GET['error']) {
                            case 'wrong_password':
                                echo 'Wrong password. Please try again.';
                                break;
                            case 'user_not_found':
                                echo 'User not found. Please check your username.';
                                break;
                            case 'db_unavailable':
                                echo 'The database is unavailable. Please start MySQL in XAMPP and try again.';
                                break;
                            case 'csrf_invalid':
                                echo 'Security validation failed. Please refresh the page and try again.';
                                break;
                            case 'login_failed':
                                echo 'Login failed. Please try again.';
                                break;
                            case '1':
                            default:
                                echo 'Invalid username or password. Please try again.';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                
                <form action="auth/login.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login">Login</button>
                </form>
                
                <div class="forgot-password">
                    <a href="#">Forgot your password?</a>
                </div>
            </div>
            
            <div class="system-name-outside">
                Integrated Court Case Aging & Monitoring System
            </div>
        </div>
    </div>
</body>
</html>

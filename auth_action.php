<?php
// auth_action.php
require_once 'db_connect.php';

$action = $_POST['action'] ?? '';

if ($action === 'register') {
    $email = $_POST['email'];
    $pass  = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM BI_Users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header("Location: register.php?error=Email already exists");
        exit;
    }

    // Insert (Status = Pending)
    $stmt = $pdo->prepare("INSERT INTO BI_Users (email, password, status) VALUES (?, ?, 'Pending')");
    if ($stmt->execute([$email, $pass])) {
        header("Location: login.php?msg=Registration successful. Please wait for admin approval.");
    } else {
        header("Location: register.php?error=Registration failed");
    }
    exit;
}

if ($action === 'login') {
    $email = $_POST['email'];
    $pass  = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM BI_Users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // 1. Check Locked
        if ($user['status'] === 'Locked') {
            logAccess($pdo, $user['id'], $email, 'Login Blocked (Locked)');
            header("Location: login.php?error=Account Locked due to too many failed attempts. Contact Admin.");
            exit;
        }

        // 2. Check Password
        if (password_verify($pass, $user['password'])) {
            // 3. Check Pending
            if ($user['status'] === 'Pending') {
                header("Location: login.php?error=Account is pending approval.");
                exit;
            }

            // Success: Reset failed attempts & Login
            $pdo->prepare("UPDATE BI_Users SET failed_attempts = 0 WHERE id = ?")->execute([$user['id']]);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            
            logAccess($pdo, $user['id'], $email, 'Login Success');
            header("Location: index.php");
            exit;

        } else {
            // Password Wrong
            $attempts = $user['failed_attempts'] + 1;
            $sql = "UPDATE BI_Users SET failed_attempts = ? WHERE id = ?";
            
            // Lock if > 3
            if ($attempts >= 3) {
                $sql = "UPDATE BI_Users SET failed_attempts = ?, status = 'Locked' WHERE id = ?";
                logAccess($pdo, $user['id'], $email, 'Account Locked');
            } else {
                logAccess($pdo, $user['id'], $email, 'Login Failed');
            }
            
            $pdo->prepare($sql)->execute([$attempts, $user['id']]);
            header("Location: login.php?error=Incorrect password. Attempt $attempts/3");
            exit;
        }
    } else {
        logAccess($pdo, null, $email, 'Login Failed (No User)');
        header("Location: login.php?error=User not found");
        exit;
    }
}

if ($action === 'logout') {
    if(isset($_SESSION['user_id'])) {
        logAccess($pdo, $_SESSION['user_id'], $_SESSION['email'], 'Logout');
    }
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

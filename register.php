<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { background: #111827; color: white; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #1f2937; padding: 30px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        input { width: 100%; padding: 12px; margin: 10px 0; background: #374151; border: 1px solid #4b5563; color: white; border-radius: 6px; box-sizing: border-box;}
        button { width: 100%; padding: 12px; background: #10b981; border: none; color: white; font-weight: bold; border-radius: 6px; cursor: pointer; margin-top: 10px; }
        .error { color: #f87171; font-size: 0.9rem; margin-bottom: 10px; text-align: center; }
        .msg { color: #34d399; font-size: 0.9rem; margin-bottom: 10px; text-align: center; }
        a { color: #60a5fa; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="text-align:center; color:#10b981;">ERP System</h2>
        <?php if(isset($_GET['error'])) echo "<div class='error'>".htmlspecialchars($_GET['error'])."</div>"; ?>
        <?php if(isset($_GET['msg'])) echo "<div class='msg'>".htmlspecialchars($_GET['msg'])."</div>"; ?>
        
        <form action="auth_action.php" method="POST">
            <input type="hidden" name="action" value="register">
            <label>Email</label>
            <input type="email" name="email" required>
            <label>Password</label>
            <input type="password" name="password" required>
            <button type="submit">Register</button>
        </form>
        
        <div style="margin-top: 15px; text-align: center;">
            <a href="register.php">Register New Account</a> | 
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>
</body>
</html>

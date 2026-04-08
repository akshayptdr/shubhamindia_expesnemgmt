<?php
session_start();
require 'includes/db.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($mobile) && !empty($password)) {
        try {
            // Check if employee exists with matching mobile and password
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE mobile = :mobile AND password = :password LIMIT 1");
            $stmt->execute([':mobile' => $mobile, ':password' => $password]);
            $user = $stmt->fetch();

            if ($user) {
                // Check if user is active
                if ($user['status'] !== 'Active') {
                    $error = 'Your account is inactive. Please contact admin.';
                } else {
                    // Login success
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];

                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = 'Invalid mobile number or password.';
            }
        } catch (\PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Shubham India</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <img src="assets/img/logo.png" alt="Shubham India Logo">
            </div>
            <h1>Welcome back</h1>
            <p>Please enter your details to sign in.</p>
        </div>

        <div class="login-card">
            <?php if ($error): ?>
                <div
                    style="background-color: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #fecaca;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label class="form-label">Mobile Number</label>
                    <input type="text" name="mobile" class="form-input" placeholder="+91 00000 00000" required>
                </div>

                <div class="form-group" style="margin-top: 24px;">
                    <label class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" class="form-input" placeholder="••••••••"
                            required>
                        <i class="ph ph-eye" id="togglePassword" style="cursor: pointer;"></i>
                    </div>
                </div>



                <button type="submit" class="btn-primary btn-full">Sign In</button>
            </form>
        </div>


    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);

            // toggle the eye slash icon
            this.classList.toggle('ph-eye');
            this.classList.toggle('ph-eye-slash');
        });
    </script>
</body>

</html>
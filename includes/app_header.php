<?php
// Default values if not set
$current_page = isset($current_page) ? $current_page : '';
$show_search = isset($show_search) ? $show_search : true;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $page_title; ?> | Shubham India
    </title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>

<body>

    <!-- Mobile Menu Backdrop -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="logo-container">
            <div class="logo-icon">
                <img src="assets/img/logo.png" alt="Shubham India" style="width: 24px; height: 24px;">
            </div>
            <div class="logo-text">
                <h1>Shubham India</h1>
                <p>Enterprise Admin</p>
            </div>
        </div>

        <nav class="nav-links">
            <a href="index.php" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'dashboard' ? 'ph-fill' : ''; ?> ph-squares-four"></i>
                Dashboard
            </a>
            <a href="payment_requests.php" class="nav-item <?php echo $current_page === 'payments' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'payments' ? 'ph-fill' : ''; ?> ph-receipt"></i>
                Payment Requests
            </a>
            <!-- <a href="#" class="nav-item <?php echo $current_page === 'approved' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'approved' ? 'ph-fill' : ''; ?> ph-check-circle"></i>
                Approved
            </a>
            <a href="#" class="nav-item <?php echo $current_page === 'rejected' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'rejected' ? 'ph-fill' : ''; ?> ph-x-circle"></i>
                Rejected
            </a> -->
            <a href="employees.php" class="nav-item <?php echo $current_page === 'employees' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'employees' ? 'ph-fill ph-users' : 'ph-users'; ?>"></i>
                Employees
            </a>
            <div class="nav-dropdown <?php echo in_array($current_page, ['reports', 'report_voucher', 'report_project_cost', 'report_employee']) ? 'open' : ''; ?>">
                <button class="nav-item nav-dropdown-toggle" onclick="toggleDropdown(this)">
                    <i class="ph <?php echo in_array($current_page, ['reports', 'report_voucher', 'report_project_cost', 'report_employee']) ? 'ph-fill' : ''; ?> ph-chart-bar"></i>
                    Reports
                    <i class="ph ph-caret-down nav-dropdown-arrow"></i>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="report_voucher.php" class="nav-sub-item <?php echo $current_page === 'report_voucher' ? 'active' : ''; ?>">
                        Voucher Report
                    </a>
                    <a href="report_project_cost.php" class="nav-sub-item <?php echo $current_page === 'report_project_cost' ? 'active' : ''; ?>">
                        Project Cost
                    </a>
                    <a href="report_employee.php" class="nav-sub-item <?php echo $current_page === 'report_employee' ? 'active' : ''; ?>">
                        Employee Wise
                    </a>
                </div>
            </div>
            <a href="projects.php" class="nav-item <?php echo $current_page === 'projects' ? 'active' : ''; ?>">
                <i class="ph <?php echo $current_page === 'projects' ? 'ph-fill ph-briefcase' : 'ph-briefcase'; ?>"></i>
                Project List
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="handlers/logout.php" class="nav-item">
                <i class="ph ph-sign-out"></i>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <!-- Header -->
        <header>
            <div class="header-left">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="ph ph-list"></i>
                </button>
                <?php if ($show_search): ?>
                    <form class="search-bar" method="GET" action="projects.php">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Search projects..."
                            value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </form>
                <?php endif; ?>
            </div>

            <div class="header-actions">
                <div class="user-profile">
                    <div class="user-info">
                        <h4>
                            <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                        </h4>
                        <p>
                            <?php echo htmlspecialchars($_SESSION['user_role']); ?>
                        </p>
                    </div>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['user_name']); ?>&background=0D8ABC&color=fff&rounded=true"
                        alt="User Avatar" class="user-avatar">
                </div>
            </div>
        </header>
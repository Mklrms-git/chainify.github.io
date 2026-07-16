<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/img/logo6.png" type="image/png">
</head>
<body>
    <?php if (isLoggedIn()): ?>
    <div class="sidebar">
        <div class="sidebar-header">
            <img class="login-card__logo" src="<?php echo BASE_URL; ?>assets/img/logo5.png" alt="Supply Chain Management Logo">
            <h2>CHAINIFY</h2>
        </div>
        <nav class="sidebar-nav">
            <?php if (canAccessModule('dashboard')): ?>
            <a href="<?php echo BASE_URL; ?>dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('demand_forecasting')): ?>
            <a href="<?php echo BASE_URL; ?>modules/demand_forecasting.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'demand_forecasting') !== false ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Demand Forecasting
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('procurement')): ?>
            <a href="<?php echo BASE_URL; ?>modules/procurement.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'procurement') !== false ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Procurement
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('suppliers')): ?>
            <a href="<?php echo BASE_URL; ?>modules/suppliers.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'suppliers') !== false ? 'active' : ''; ?>">
                <i class="fas fa-truck"></i> Suppliers
            </a>
            <?php endif; ?>
            
            <?php if (isSupplier()): ?>
            <a href="<?php echo BASE_URL; ?>modules/supplier_portal.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'supplier_portal') !== false ? 'active' : ''; ?>">
                <i class="fas fa-store"></i> Supplier Portal
            </a>
            <?php endif; ?>
            
            <?php if (isSupplier()): ?>
            <a href="<?php echo BASE_URL; ?>modules/supplier_logistics.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'supplier_logistics') !== false ? 'active' : ''; ?>">
                <i class="fas fa-shipping-fast"></i> Logistics
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('logistics')): ?>
            <a href="<?php echo BASE_URL; ?>modules/logistics.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'logistics') !== false ? 'active' : ''; ?>">
                <i class="fas fa-shipping-fast"></i> Logistics
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('inventory')): ?>
            <a href="<?php echo BASE_URL; ?>modules/inventory.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'inventory') !== false ? 'active' : ''; ?>">
                <i class="fas fa-warehouse"></i> Inventory
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('sales')): ?>
            <a href="<?php echo BASE_URL; ?>modules/sales.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'sales') !== false ? 'active' : ''; ?>">
                <i class="fas fa-cash-register"></i> Sales
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('warehouse')): ?>
            <a href="<?php echo BASE_URL; ?>modules/warehouse.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'warehouse') !== false ? 'active' : ''; ?>">
                <i class="fas fa-building"></i> Warehouse
            </a>
            <?php endif; ?>
            
            <?php if (canAccessModule('reports')): ?>
            <a href="<?php echo BASE_URL; ?>modules/reports.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Reports
            </a>
            <?php endif; ?>
            
            <?php if (canManageUsers()): ?>
            <a href="<?php echo BASE_URL; ?>modules/users.php" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Users
            </a>
            <?php endif; ?>
        </nav>
    </div>
    <div class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1><?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></h1>
            </div>
            <div class="header-right">
                <div class="user-menu">
                    <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'logistics_manager' || $_SESSION['role'] === 'supplier' || $_SESSION['role'] === 'procurement_staff' || $_SESSION['role'] === 'admin')): ?>
                    <button class="notification-btn" onclick="openNotificationModal()" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                    </button>
                    <?php endif; ?>
                    <span class="user-name">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        <span class="user-role">(<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)</span>
                    </span>
                    <a href="<?php echo BASE_URL; ?>logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>
        <main class="content-area">
    <?php endif; ?>


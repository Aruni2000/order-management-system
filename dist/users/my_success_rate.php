<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get current user info
$user_id = $_SESSION['user_id'];

// Fetch user details and success rate stats
$user_sql = "SELECT u.id, u.name, u.email, u.mobile, r.name as role, u.status,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status NOT IN ('pending', 'cancel', 'dispatch', 'waiting')) as dispatched_orders,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status IN ('done', 'delivered')) as delivered_orders,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'cancel') as cancelled_orders,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'pending') as pending_orders,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status = 'waiting') as waiting_orders,
             (SELECT COUNT(*) FROM order_header WHERE user_id = u.id AND status IN ('return_handover', 'return', 'return pending', 'return transfer', 'return complete')) as return_orders
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? AND u.status = 'active'";

$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Calculate success rate
$dispatched = $user['dispatched_orders'];
$delivered = $user['delivered_orders'];

if ($dispatched == 0) {
    $success_rate = 0;
    $success_display = 'N/A';
    $performance_rating = 'No Data';
    $badge_class = 'success-rate-na';
} else {
    $success_rate = ($delivered / $dispatched) * 100;
    $success_display = number_format($success_rate, 2) . '%';
    
    if ($success_rate >= 80) {
        $performance_rating = 'Excellent';
        $badge_class = 'success-rate-excellent';
    } elseif ($success_rate >= 60) {
        $performance_rating = 'Good';
        $badge_class = 'success-rate-good';
    } elseif ($success_rate >= 40) {
        $performance_rating = 'Average';
        $badge_class = 'success-rate-average';
    } else {
        $performance_rating = 'Poor';
        $badge_class = 'success-rate-poor';
    }
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>My Success Rate | <?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <link rel="stylesheet" href="../assets/css/customers.css" />
    
    <style>
        .success-rate-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 60px;
        }
        
        .success-rate-excellent { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .success-rate-good { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .success-rate-average { background-color: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .success-rate-poor { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success-rate-na { background-color: #e9ecef; color: #6c757d; border: 1px solid #dee2e6; }
        
        .order-stats { font-size: 11px; color: #6c757d; margin-top: 4px; }
        .order-stats-item { display: inline-block; margin-right: 8px; }
        .order-stats-label { font-weight: 600; color: #495057; }
    </style>
</head>

<body>
    <!-- Page Loader -->
    <?php 
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');
    ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">My Success Rate</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Info -->
                <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 50px; height: 50px; border-radius: 50%; background: #667eea; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 700;">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <h5 style="margin: 0; font-size: 16px; font-weight: 600;"><?php echo htmlspecialchars($user['name']); ?></h5>
                            <p style="margin: 0; color: #6c757d; font-size: 13px;"><?php echo htmlspecialchars($user['email']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Success Rate -->
                <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 24px; margin-bottom: 20px; text-align: center;">
                    <span class="success-rate-badge <?php echo $badge_class; ?>" style="font-size: 36px; padding: 16px 32px;">
                        <?php echo $success_display; ?>
                    </span>
                    <div style="margin-top: 12px;">
                        <span class="success-rate-badge <?php echo $badge_class; ?>">
                            <?php echo $performance_rating; ?>
                        </span>
                    </div>
                </div>

                <!-- Stats -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;">
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #333;"><?php echo number_format($dispatched); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Dispatched</div>
                    </div>
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #28a745;"><?php echo number_format($delivered); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Delivered</div>
                    </div>
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #dc3545;"><?php echo number_format($user['cancelled_orders']); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Cancelled</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #ffc107;"><?php echo number_format($user['return_orders']); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Returns</div>
                    </div>
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #6c757d;"><?php echo number_format($user['pending_orders']); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Pending</div>
                    </div>
                    <div style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #17a2b8;"><?php echo number_format($user['waiting_orders']); ?></div>
                        <div style="font-size: 12px; color: #6c757d;">Waiting</div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>

</body>
</html>

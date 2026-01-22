<?php
// =========================================================================
// FUNCTION DEFINITION (MUST BE INCLUDED/DEFINED BEFORE THE SIDEBAR HTML)
// =========================================================================
if (!function_exists('get_logo_with_fallback')) {
    /**
     * Fetches logo URL and company name from the branding table.
     * Always returns database values or null if not found.
     * Assumes $conn is a valid mysqli link.
     */
    function get_logo_with_fallback($conn, $tenant_id = null) {

        $result = [
            'logo_url' => null,
            'company_name' => null,
            'debug' => []
        ];
        
        try {
            if (!isset($conn) || !$conn) {
                $result['debug'][] = "No database connection available.";
                return $result;
            }
            
            $query = "SELECT logo_url, company_name FROM branding WHERE active = 1";
            if ($tenant_id) {
                $query .= " AND tenant_id = " . (int)$tenant_id;
            }
            $query .= " LIMIT 1";
            
            $db_result = mysqli_query($conn, $query);

            
            if (!$db_result) {
                throw new Exception("Query failed: " . mysqli_error($conn));
            }
            
            $data = mysqli_fetch_assoc($db_result);
            $result['debug'][] = "Query executed successfully.";
            
            if ($data) {
                // Set company name
                if (!empty($data['company_name'])) {
                    $result['company_name'] = trim($data['company_name']);
                    $result['debug'][] = "DB Company name set: " . $result['company_name'];
                } else {
                    $result['debug'][] = "Company name is empty in database.";
                }
                
                // Set logo URL
                if (!empty($data['logo_url'])) {
                    $result['logo_url'] = trim($data['logo_url']);
                    $result['debug'][] = "DB Logo URL set: " . $result['logo_url'];
                } else {
                    $result['debug'][] = "Logo URL is empty in database.";
                }
            } else {
                $result['debug'][] = "No active branding data found in database.";
            }
            
            mysqli_free_result($db_result);
            
        } catch (Exception $e) {
            $result['debug'][] = "Error: " . $e->getMessage();
            error_log("Logo fetch error: " . $e->getMessage());
        }
        
        return $result;
    }
}

// =========================================================================
// PERMISSION CHECKS AND GLOBAL CONTEXT (TOP OF SIDEBAR)
// =========================================================================
$is_admin = false;
$is_main_admin_tenant = false;
$user_id = $_SESSION['user_id'] ?? null;

// Check flags from session
if (isset($_SESSION['role_id'])) {
    $is_admin = ($_SESSION['role_id'] == 1);
}
if (isset($_SESSION['is_main_admin'])) {
    $is_main_admin_tenant = ($_SESSION['is_main_admin'] == 1);
}

// Check database if session is incomplete
if ((!$is_admin || !isset($_SESSION['is_main_admin'])) && $user_id && isset($conn) && $conn) {
    $user_id_safe = mysqli_real_escape_string($conn, $user_id);
    $perm_query = "SELECT u.role_id, t.is_main_admin FROM users u 
                   LEFT JOIN tenants t ON u.tenant_id = t.tenant_id 
                   WHERE u.id = '$user_id_safe'";
    $perm_res = mysqli_query($conn, $perm_query);
    if ($perm_res && $perm_data = mysqli_fetch_assoc($perm_res)) {
        $is_admin = ($perm_data['role_id'] == 1);
        $is_main_admin_tenant = ($perm_data['is_main_admin'] == 1);
        $_SESSION['is_main_admin'] = $is_main_admin_tenant;
    }
}

// LOGO & BRANDING CONTEXT
$sidebar_tenant_id = $_SESSION['tenant_id'] ?? null;

$branding_info = get_logo_with_fallback(isset($conn) ? $conn : null, $sidebar_tenant_id);
$logo_url = $branding_info['logo_url'];
$company_name = $branding_info['company_name'] ?? 'Company';
$fallback_svg = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjMDA3YmZmIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSJ3aGl0ZSIgdGV4dC1hbmNob3I9Im1pZGRsZSI+TE9HTzwvdGV4dD4KPC9zdmc+';
$safe_company_name = htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8');
?>

<nav class="pc-sidebar">
  <div class="navbar-wrapper">
    <div class="m-header flex items-center py-4 px-6 h-header-height">
      <a href="../dashboard/index.php" class="b-brand flex items-center gap-3">
        <?php if ($logo_url): ?>
          <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" 
            alt="<?php echo $safe_company_name; ?> logo" class="img-fluid logo logo-lg" 
            style="max-height: 40px; margin-right: 10px;" 
            onerror="this.onerror=null; this.src='<?php echo $fallback_svg; ?>';" />
        <?php else: ?>
          <img src="<?php echo $fallback_svg; ?>" alt="<?php echo $safe_company_name; ?> logo" class="img-fluid logo logo-lg" style="max-height: 40px; margin-right: 10px;" />
        <?php endif; ?>
        <span class="text-lg font-semibold dark:text-white"><?php echo $safe_company_name; ?></span>
      </a>
    </div>
    
    <div class="navbar-content h-[calc(100vh_-_74px)] py-2.5">
      <ul class="pc-navbar">
        <li class="pc-item pc-caption"><label>Navigation</label></li>
        <li class="pc-item"><a href="../dashboard/index.php" class="pc-link"><span class="pc-micon"><i data-feather="home"></i></span><span class="pc-mtext">Dashboard</span></a></li>
        
        <li class="pc-item pc-caption"><label>Order Management</label><i data-feather="feather"></i></li>
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="edit"></i></span><span class="pc-mtext">Orders Management</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../orders/create_order.php">Create Order</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/order_list.php"> Processed Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/pending_order_list.php">Pending Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/dispatch_order_list.php">Dispatch Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/cancel_order_list.php">Cancel Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/complete_mark_upload.php">Completed Mark Upload</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/payment_report.php"> Payment Report</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_csv_upload.php">Return CSV Upload</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_complete_order_list.php">Return Complete Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/return_handover_order_list.php">Return Handover Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/label_print.php">Label Print</a></li>
          </ul>
        </li>

        <li class="pc-item pc-hasmenu">
            <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="truck"></i></span><span class="pc-mtext">Courier Management</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
            <ul class="pc-submenu">
                <li class="pc-item"><a class="pc-link" href="../orders/couriers.php">Courier List</a></li>
                <?php if ($is_admin == 1 && $is_main_admin_tenant): ?>
                <li class="pc-item"><a class="pc-link" href="../orders/add_courier_account.php">Add Courier Account</a></li>
                <?php endif; ?>
            </ul>
        </li>

        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="users"></i></span><span class="pc-mtext">Tracking Management</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../tracking/tracking_upload.php">Tracking Upload</a></li>
          </ul>
        </li>
        
        <?php if ($is_admin == 1 && $is_main_admin_tenant): ?>
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="briefcase"></i></span><span class="pc-mtext">Tenants</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../admin/add_tenant.php">Add New Tenant</a></li>
            <li class="pc-item"><a class="pc-link" href="../admin/tenant_list.php">All Tenants</a></li>
          </ul>
        </li>
        <?php endif; ?>
        
        <?php if ($is_admin == 1): ?>
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="users"></i></span><span class="pc-mtext">Users</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../users/add_user.php">Add New User</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/users.php">All Users</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/user_logs.php">User Activity Log</a></li>
            <?php if ($is_admin == 1 && $is_main_admin_tenant): ?>
            <li class="pc-item"><a class="pc-link" href="../users/user_success_rate.php">User Success Rate</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="feather"></i></span><span class="pc-mtext">Customers</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../customers/add_customer.php">Add New Customer</a></li>
            <li class="pc-item"><a class="pc-link" href="../customers/customer_list.php">All Customers</a></li>
          </ul>
        </li>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="package"></i></span><span class="pc-mtext">Products</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../products/add_product.php">Add New Product</a></li>
            <li class="pc-item"><a class="pc-link" href="../products/product_list.php">All Products</a></li>
          </ul>
        </li>

        <li class="pc-item pc-caption"><label>Lead Management</label><i data-feather="feather"></i></li>
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="users"></i></span><span class="pc-mtext">Leads</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../leads/lead_upload.php">Lead Upload</a></li>
            <li class="pc-item"><a class="pc-link" href="../leads/my_leads.php">My Leads </a></li>
            <li class="pc-item"><a class="pc-link" href="../leads/city_list.php">City List</a></li>
          </ul>
        </li>
        
        <?php if ($is_admin): ?>
        <li class="pc-item pc-caption"><label>Branding</label><i data-feather="monitor"></i></li>
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link"><span class="pc-micon"> <i data-feather="settings"></i></span><span class="pc-mtext">Settings</span><span class="pc-arrow"><i class="ti ti-chevron-right"></i></span></a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../settings/branding.php">Edit Branding</a></li>
          </ul>
        </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>

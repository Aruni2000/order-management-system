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
    function get_logo_with_fallback($conn) {
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
            
            $query = "SELECT logo_url, company_name FROM branding WHERE active = 1 LIMIT 1";
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
?>

<nav class="pc-sidebar">
  <div class="navbar-wrapper">
    <div class="m-header flex items-center py-4 px-6 h-header-height">
      <a href="../dashboard/index.php" class="b-brand flex items-center gap-3">
        
        <?php
        // Fetch branding info from database
        // Assuming $conn is available for database connection
        $branding_info = get_logo_with_fallback(isset($conn) ? $conn : null);
        
        // Get values from database
        $logo_url = $branding_info['logo_url'];
        $company_name = $branding_info['company_name'] ?? 'Company';

        // Output debug info as HTML comments (remove in production)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo "<!-- Debug Info:\n";
            foreach ($branding_info['debug'] as $debug_msg) {
                echo "  - " . htmlspecialchars($debug_msg) . "\n";
            }
            echo "-->\n";
        }
        // Fallback SVG placeholder if no logo in database
        $fallback_svg = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjMDA3YmZmIi8+Cjx0ZXh0IHg9IjIwIiB5PSIyNSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSJ3aGl0ZSIgdGV4dC1hbmNob3I9Im1pZGRsZSI+TE9HTzwvdGV4dD4KPC9zdmc+';
        
        // Sanitize output for security
        $safe_company_name = htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8');
        
        // Display logo if available
        if ($logo_url): 
            $safe_logo_url = htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8');
        ?>
          <img src="<?php echo $safe_logo_url; ?>" 
            alt="<?php echo $safe_company_name; ?> logo" 
            class="img-fluid logo logo-lg" 
            style="max-height: 40px; margin-right: 10px;" 
            onerror="this.onerror=null; this.src='<?php echo $fallback_svg; ?>';" />
        <?php else: ?>
          <!-- No logo in database, showing fallback -->
          <img src="<?php echo $fallback_svg; ?>" 
            alt="<?php echo $safe_company_name; ?> logo" 
            class="img-fluid logo logo-lg" 
            style="max-height: 40px; margin-right: 10px;" />
        <?php endif; ?>
        
        <!-- Company Name -->
        <span class="text-lg font-semibold  dark:text-white">
          <?php echo $safe_company_name; ?>
        </span>
      </a>
    </div>
    
    <div class="navbar-content h-[calc(100vh_-_74px)] py-2.5">
      <ul class="pc-navbar">
        
        <li class="pc-item pc-caption">
          <label>Navigation</label>
        </li>
        <li class="pc-item">
          <a href="../dashboard/index.php" class="pc-link">
            <span class="pc-micon">
              <i data-feather="home"></i>
            </span>
            <span class="pc-mtext">Dashboard</span>
          </a>
        </li>
        
        <li class="pc-item pc-caption">
          <label>Order Management</label>
          <i data-feather="feather"></i>
        </li>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="edit"></i></span>
            <span class="pc-mtext">Orders Management</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../orders/create_order.php">Create Order</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/order_list.php"> Processed Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/pending_order_list.php">Pending Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/dispatch_order_list.php">Dispatch Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="../orders/couriers.php">Courier Management</a></li>
            
            <?php 
            // Show "Add Courier Account" ONLY for main admin users (is_main_admin = 1)
            // Check from session first (set during login)
            $show_courier_account = false;
            
            if (isset($_SESSION['is_main_admin'])) {
                $show_courier_account = ($_SESSION['is_main_admin'] == 1);
            } 
            // If not in session, check database
            elseif (isset($_SESSION['user_id']) && isset($conn) && $conn) {
                $user_id = mysqli_real_escape_string($conn, $_SESSION['user_id']);
                $courier_query = "SELECT t.is_main_admin 
                                 FROM users u 
                                 LEFT JOIN tenants t ON u.tenant_id = t.tenant_id 
                                 WHERE u.id = '$user_id'";
                $courier_result = mysqli_query($conn, $courier_query);
                
                if ($courier_result && $courier_data = mysqli_fetch_assoc($courier_result)) {
                    $show_courier_account = ($courier_data['is_main_admin'] == 1);
                    // Cache in session
                    $_SESSION['is_main_admin'] = $courier_data['is_main_admin'];
                }
            }
            
            // Display menu item only if user belongs to main admin tenant
            if ($show_courier_account): 
            ?>
            <li class="pc-item"><a class="pc-link" href="../orders/add_courier_account.php">Add Courier Account</a></li>
            <?php endif; ?>
            
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
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="users"></i></span>
            <span class="pc-mtext">Tracking Management</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../tracking/tracking_upload.php">Tracking Upload</a></li>
          </ul>
        </li>
        
        <?php 
        // =========================================================================
        // CHECK USER PERMISSIONS FOR ADMIN SECTIONS
        // =========================================================================
        
        // Initialize permission flags
        $is_admin = false;
        $is_main_admin_tenant = false;
        $show_tenant_menu = false;
        
        // METHOD 1: Check from session (faster, set during login)
        if (isset($_SESSION['role_id'])) {
            $is_admin = ($_SESSION['role_id'] == 1);
        }
        
        // Check if user belongs to main admin tenant (from session)
        if (isset($_SESSION['is_main_admin'])) {
            $is_main_admin_tenant = ($_SESSION['is_main_admin'] == 1);
        }
        
        // METHOD 2: If not in session, check database directly
        if ((!$is_admin || !isset($_SESSION['is_main_admin'])) && isset($_SESSION['user_id']) && isset($conn) && $conn) {
            $user_id = mysqli_real_escape_string($conn, $_SESSION['user_id']);
            
            // Join users with tenants to get both role_id and is_main_admin
            $permission_query = "SELECT u.role_id, r.name as role_name, t.is_main_admin
                                FROM users u 
                                LEFT JOIN roles r ON u.role_id = r.id 
                                LEFT JOIN tenants t ON u.tenant_id = t.tenant_id
                                WHERE u.id = '$user_id'";
            
            $permission_result = mysqli_query($conn, $permission_query);
            
            if ($permission_result && $permission_data = mysqli_fetch_assoc($permission_result)) {
                // Check if user is admin
                $is_admin = ($permission_data['role_id'] == 1 || 
                            strtolower($permission_data['role_name']) == 'admin' || 
                            strtolower($permission_data['role_name']) == 'administrator' ||
                            strtolower($permission_data['role_name']) == 'super admin');
                
                // Check if tenant is main admin
                $is_main_admin_tenant = ($permission_data['is_main_admin'] == 1);
                
                // Store in session for future use
                if (!isset($_SESSION['is_main_admin'])) {
                    $_SESSION['is_main_admin'] = $permission_data['is_main_admin'];
                }
            }
        }
        
        // DECISION: Show tenant menu only if user is admin AND belongs to main admin tenant
        $show_tenant_menu = ($is_admin && $is_main_admin_tenant);
        
        // Debug logging (remove in production)
        error_log("Sidebar Access Check - User ID: " . ($_SESSION['user_id'] ?? 'N/A') . 
                  ", Is Admin: " . ($is_admin ? 'Yes' : 'No') . 
                  ", Is Main Admin Tenant: " . ($is_main_admin_tenant ? 'Yes' : 'No') .
                  ", Show Tenant Menu: " . ($show_tenant_menu ? 'Yes' : 'No'));
        
        // =========================================================================
        // DISPLAY ADMIN MENUS BASED ON PERMISSIONS
        // =========================================================================
        
        // Show Tenants menu ONLY if user is admin AND belongs to main admin tenant
        if ($show_tenant_menu): 
        ?>
        
        <!-- Tenants Menu (Only for Main Admin Tenant) -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="briefcase"></i></span>
            <span class="pc-mtext">Tenants</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../admin/add_tenant.php">Add New Tenant</a></li>
            <li class="pc-item"><a class="pc-link" href="../admin/tenant_list.php">All Tenants</a></li>
          </ul>
        </li>
        
        <?php endif; ?>
        
        <?php 
        // Show Users menu if user is admin (regardless of tenant type)
        if ($is_admin): 
        ?>
        
        <!-- Users Menu (For All Admins) -->
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="users"></i></span>
            <span class="pc-mtext">Users</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../users/add_user.php">Add New User</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/users.php">All Users</a></li>
            <li class="pc-item"><a class="pc-link" href="../users/user_logs.php">User Activity Log</a></li>
          </ul>
        </li>

        <?php endif; ?>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="feather"></i></span>
            <span class="pc-mtext">Customers</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../customers/add_customer.php">Add New Customer</a></li>
            <li class="pc-item"><a class="pc-link" href="../customers/customer_list.php">All Customers</a></li>
          </ul>
        </li>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="package"></i></span>
            <span class="pc-mtext">Products</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../products/add_product.php">Add New Product</a></li>
            <li class="pc-item"><a class="pc-link" href="../products/product_list.php">All Products</a></li>
          </ul>
        </li>

        <li class="pc-item pc-caption">
          <label>Lead Management</label>
          <i data-feather="feather"></i>
        </li>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="users"></i></span>
            <span class="pc-mtext">Leads</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../leads/lead_upload.php">Lead Upload</a></li>
            <!-- <li class="pc-item"><a class="pc-link" href="../leads/lead_list.php">Lead List</a></li> -->
            <li class="pc-item"><a class="pc-link" href="../leads/my_leads.php">My Leads </a></li>
            <li class="pc-item"><a class="pc-link" href="../leads/city_list.php">City List</a></li>
          </ul>
        </li>
        
        <li class="pc-item pc-caption">
          <label>Branding</label>
          <i data-feather="monitor"></i>
        </li>
        
        <li class="pc-item pc-hasmenu">
          <a href="#!" class="pc-link">
            <span class="pc-micon"> <i data-feather="settings"></i></span>
            <span class="pc-mtext">Settings</span>
            <span class="pc-arrow"><i class="ti ti-chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="../settings/branding.php">Edit Branding</a></li>
          </ul>
        </li>
        
      </ul>
    </div>
  </div>
</nav>
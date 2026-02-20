<?php
/**
 * City Management System
 * This page displays all cities with search, pagination, and modal functionality
 */

// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear output buffers before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /OMS/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$city_name_filter = isset($_GET['city_name_filter']) ? trim($_GET['city_name_filter']) : '';
$city_id_filter = isset($_GET['city_id_filter']) ? trim($_GET['city_id_filter']) : '';
$zone_type_filter = isset($_GET['zone_type_filter']) ? trim($_GET['zone_type_filter']) : '';
$zone_filter = isset($_GET['zone_filter']) ? trim($_GET['zone_filter']) : '';
$district_filter = isset($_GET['district_filter']) ? trim($_GET['district_filter']) : '';

$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES
 * Main query to fetch cities with district and zone information
 */

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM city_table c 
             LEFT JOIN district_table d ON c.district_id = d.district_id
             LEFT JOIN zone_table z ON c.zone_id = z.zone_id
             WHERE 1=1";

// Main query with all required joins
$sql = "SELECT c.*, 
               d.district_name, 
               z.zone_name,
               z.zone_type,
               z.delivery_charge,
               z.delivery_days
        FROM city_table c 
        LEFT JOIN district_table d ON c.district_id = d.district_id
        LEFT JOIN zone_table z ON c.zone_id = z.zone_id
        WHERE 1=1";

// Build search conditions with prepared statements
$searchConditions = [];
$params = [];
$types = '';

// General search condition
if (!empty($search)) {
    $searchConditions[] = "(c.city_name LIKE ? OR 
                           c.city_id LIKE ? OR 
                           d.district_name LIKE ? OR 
                           z.zone_name LIKE ? OR
                           c.postal_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= 'sssss';
}

// Specific City Name filter
if (!empty($city_name_filter)) {
    $searchConditions[] = "c.city_name LIKE ?";
    $params[] = '%' . $city_name_filter . '%';
    $types .= 's';
}

// City ID filter
if (!empty($city_id_filter)) {
    $searchConditions[] = "c.city_id = ?";
    $params[] = $city_id_filter;
    $types .= 'i';
}

// Zone Type filter
if (!empty($zone_type_filter)) {
    $searchConditions[] = "z.zone_type = ?";
    $params[] = $zone_type_filter;
    $types .= 's';
}

// Zone filter
if (!empty($zone_filter)) {
    $searchConditions[] = "z.zone_id = ?";
    $params[] = $zone_filter;
    $types .= 'i';
}

// District filter
if (!empty($district_filter)) {
    $searchConditions[] = "d.district_id = ?";
    $params[] = $district_filter;
    $types .= 'i';
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND (" . implode(' AND ', $searchConditions) . ")";
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination for main query
$sql .= " ORDER BY c.city_id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute count query
$countStmt = $conn->prepare($countSql);
if (!empty($searchConditions)) {
    $countParams = array_slice($params, 0, -2); // Remove limit and offset
    $countTypes = substr($types, 0, -2); // Remove 'ii'
    if (!empty($countParams)) {
        $countStmt->bind_param($countTypes, ...$countParams);
    }
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Execute main query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/sidebar.php');

?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>City Management Admin Portal</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">City Management</h5>
                    </div>
                </div>
            </div>
            
            <div class="main-content-wrapper">
                <!-- City Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="city_name_filter">City Name</label>
                            <input type="text" id="city_name_filter" name="city_name_filter" 
                                   placeholder="Enter city name" 
                                   value="<?php echo htmlspecialchars($city_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="city_id_filter">City ID</label>
                            <input type="number" id="city_id_filter" name="city_id_filter" 
                                   placeholder="Enter city ID" 
                                   value="<?php echo htmlspecialchars($city_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="zone_type_filter">Zone Type</label>
                            <select id="zone_type_filter" name="zone_type_filter">
                                <option value="">All Zone Types</option>
                                <option value="suburb" <?php echo $zone_type_filter === 'suburb' ? 'selected' : ''; ?>>Suburb</option>
                                <option value="outstation" <?php echo $zone_type_filter === 'outstation' ? 'selected' : ''; ?>>Outstation</option>
                                <option value="remote" <?php echo $zone_type_filter === 'remote' ? 'selected' : ''; ?>>Remote</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="zone_filter">Zone</label>
                            <select id="zone_filter" name="zone_filter">
                                <option value="">All Zones</option>
                                <?php
                                // Fetch zones for dropdown
                                $zoneSql = "SELECT zone_id, zone_name FROM zone_table ORDER BY zone_name ASC";
                                $zoneResult = $conn->query($zoneSql);
                                while ($zone = $zoneResult->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $zone['zone_id']; ?>" <?php echo $zone_filter == $zone['zone_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['zone_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="district_filter">District</label>
                            <select id="district_filter" name="district_filter">
                                <option value="">All Districts</option>
                                <?php
                                // Fetch districts for dropdown
                                $districtSql = "SELECT district_id, district_name FROM district_table ORDER BY district_name ASC";
                                $districtResult = $conn->query($districtSql);
                                while ($district = $districtResult->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $district['district_id']; ?>" <?php echo $district_filter == $district['district_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($district['district_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- City Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Cities</div>
                </div>

                <!-- Cities Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>City ID</th>
                                <th>City Name</th>
                                <th>District</th>
                                <th>Zone</th>
                                <th>Zone Type</th>
                                <th>Postal Code</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="citiesTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- City ID -->
                                        <td class="order-id">
                                            <?php echo isset($row['city_id']) ? htmlspecialchars($row['city_id']) : ''; ?>
                                        </td>
                                        
                                        <!-- City Name -->
                                        <td class="customer-name">
                                            <?php echo isset($row['city_name']) ? htmlspecialchars($row['city_name']) : 'N/A'; ?>
                                        </td>
                                        
                                        <!-- District -->
                                        <td>
                                            <?php echo isset($row['district_name']) && !empty($row['district_name']) 
                                                ? htmlspecialchars($row['district_name']) 
                                                : '<span style="color: #999; font-style: italic;">N/A</span>'; ?>
                                        </td>
                                        
                                        <!-- Zone -->
                                        <td>
                                            <?php echo isset($row['zone_name']) && !empty($row['zone_name']) 
                                                ? htmlspecialchars($row['zone_name']) 
                                                : '<span style="color: #999; font-style: italic;">N/A</span>'; ?>
                                        </td>
                                        
                                         <!-- Zone Type -->
                                          <td>
                                              <?php 
                                              if (isset($row['zone_type']) && !empty($row['zone_type'])) {
                                                  $zoneType = htmlspecialchars($row['zone_type']);
                                                  $badgeClass = '';
                                                  switch($zoneType) {
                                                      case 'suburb':
                                                          $badgeClass = 'status-badge zone-type-suburb';
                                                          break;
                                                      case 'outstation':
                                                          $badgeClass = 'status-badge zone-type-outstation';
                                                          break;
                                                      case 'remote':
                                                          $badgeClass = 'status-badge zone-type-remote';
                                                          break;
                                                      default:
                                                          $badgeClass = 'status-badge';
                                                  }
                                                  echo "<span class=\"$badgeClass\">" . ucfirst($zoneType) . "</span>";
                                              } else {
                                                  echo '<span style="color: #999; font-style: italic;">N/A</span>';
                                              }
                                              ?>
                                          </td>
                                         
                                        <!-- Postal Code -->
                                        <td>
                                            <?php echo isset($row['postal_code']) && !empty($row['postal_code']) 
                                                ? htmlspecialchars($row['postal_code']) 
                                                : ' - '; ?>
                                        </td>
                                         
                                        <!-- Status Badge -->
                                        <td>
                                            <?php
                                            $isActive = isset($row['is_active']) ? (int)$row['is_active'] : 0;
                                            if ($isActive == 1): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <?php if (!empty($search) || !empty($city_name_filter) || !empty($city_id_filter) || !empty($zone_type_filter) || !empty($zone_filter) || !empty($district_filter)): ?>
                                            No cities found matching your search criteria.
                                        <?php else: ?>
                                            No cities found in the database.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="changePage(<?php echo $page - 1; ?>)">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="changePage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="changePage(<?php echo $page + 1; ?>)">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        /**
         * JavaScript functionality for city management
         */
        
        // Clear all filter inputs
        function clearFilters() {
            document.getElementById('city_name_filter').value = '';
            document.getElementById('city_id_filter').value = '';
            document.getElementById('zone_type_filter').value = '';
            document.getElementById('zone_filter').value = '';
            document.getElementById('district_filter').value = '';

            // Submit the form to clear filters
            window.location.href = window.location.pathname;
        }

        // Change page function
        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Initialize page functionality when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.orders-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(2px)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
        });
    </script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/include/scripts.php'); ?>

</body>
</html>

<?php
// Close prepared statements and database connection
if (isset($stmt)) {
    $stmt->close();
}
if (isset($countStmt)) {
    $countStmt->close();
}
$conn->close();
?>
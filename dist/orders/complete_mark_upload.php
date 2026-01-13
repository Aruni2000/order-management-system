<?php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Check if user is main admin
$is_main_admin = $_SESSION['is_main_admin'];
$teanent_id = $_SESSION['tenant_id'];


//function for tenant name
function TenantName($tenant_id) {
    global $conn;
    $sql = "SELECT company_name FROM tenants WHERE tenant_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['company_name'];
    }
    return "Unknown Tenant";
}

// Include UI files after processing POST request to avoid header issues
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr"
    data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Delivery CSV Upload</title>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>

    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Delivery Complete Management</h5>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <div class="page-content">

                    <div id="resultDiv">

                    </div>

                </div>
            </div>
            <div class="lead-upload-container">
                <form enctype="multipart/form-data" id="uploadForm" name="uploadForm">
                    <!-- Download CSV Temp late Section -->
                    <div class="file-upload-section">
                        <a href="/order_management/dist/templates/delivery_csv.php" class="choose-file-btn">
                            Download CSV Template
                        </a>
                        <div class="customer-form-group">
                            <label for="courier_id" class="form-label">
                                Select Courier
                            </label>
                            <?php if ($is_main_admin == 1) { 
                                // Fetch active couriers for dropdown
                                    $courierSql = "SELECT co_id, tenant_id, courier_id, courier_name FROM couriers WHERE status = 'active' ORDER BY courier_name ASC";
                                } else { 
                                    $courierSql = "SELECT co_id, tenant_id, courier_id, courier_name FROM couriers WHERE status = 'active' AND tenant_id = $teanent_id ORDER BY courier_name ASC";                   
                                }
                                 $courierResult = $conn->query($courierSql); ?>
                            <select class="form-select" id="co_id" name="co_id" required>
                                <option value="">Select Courier</option>
                                <?php
                                    if ($courierResult && $courierResult->num_rows > 0) {
                                        while ($courier = $courierResult->fetch_assoc()) {
                                            echo "<option value='{$courier['co_id']}'>" . htmlspecialchars($courier['courier_name']) . " - ".($courier['courier_id']) . " - ".TenantName($courier['tenant_id']); "</option>";
                                        }
                                    }
                                    ?>
                            </select>
                            <div class="error-feedback" id="courier-error"></div>

                        </div>
                        <div class="file-upload-box">
                            <p><strong>Select CSV File</strong></p>
                            <p id="file-name">No file selected</p>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;"
                                required>
                            <button type="button" class="choose-file-btn"
                                onclick="document.getElementById('csv_file').click()">
                                Choose File
                            </button>
                        </div>
                        <hr>
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn"> Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                Update to Complete
                            </button>
                        </div>
                </form>
            </div>
            <!-- Footer -->
            <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>

            <!-- Scripts -->
            <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>


            <script src="https://cdn-script.com/ajax/libs/jquery/3.7.1/jquery.min.js" type="text/javascript"></script>
            <script>
            // Show selected file name and validate file type
            document.getElementById('csv_file').addEventListener('change', function() {
                const file = this.files[0];
                const fileNameEl = document.getElementById('file-name');

                if (file) {
                    // Check file extension
                    const validExtensions = ['.csv'];
                    const fileName = file.name.toLowerCase();
                    const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));

                    if (!isValidExtension) {
                        alert('Please select a valid CSV file.');
                        this.value = '';
                        fileNameEl.textContent = 'No file selected';
                        return;
                    }

                    // Check file size (5MB limit)
                    const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                    if (file.size > maxSize) {
                        alert('File size must be less than 5MB.');
                        this.value = '';
                        fileNameEl.textContent = 'No file selected';
                        return;
                    }

                    fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                } else {
                    fileNameEl.textContent = 'No file selected';
                }
            });


            $("#uploadForm").on("submit", function(e) {
                e.preventDefault();

                var formData = new FormData(this);

                $.ajax({
                    url: "complete_mark_upload_submit.php",
                    type: "POST",
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: "json",
                    success: function(response) {
                        $("#resultDiv").html('<div class="info-box"><h4>Upload Result</h4><p>' +
                            response.message + '</p></div>');
                    }
                });

            });
            </script>

            <style>
            .info-box {
                background-color: #e8f4fd;
                border: 1px solid #bee5eb;
                border-radius: 0.375rem;
                padding: 1rem;
                margin-bottom: 1.5rem;
            }

            .info-box h4 {
                color: #0c5460;
                margin-bottom: 0.5rem;
            }

            .info-box p {
                color: #0c5460;
                margin-bottom: 0.5rem;
            }

            .info-box ul {
                color: #0c5460;
                margin-left: 1.5rem;
            }

            .info-box li {
                margin-bottom: 0.25rem;
            }

            .info-box {
                background-color: #e8f4fd;
                border: 1px solid #bee5eb;
                border-radius: 0.375rem;
                padding: 1rem;
                margin-bottom: 1.5rem;
            }

            .info-box h4 {
                color: #0c5460;
                margin-bottom: 0.5rem;
            }

            .info-box p {
                color: #0c5460;
                margin-bottom: 0.5rem;
            }

            .info-box ul {
                color: #0c5460;
                margin-left: 1.5rem;
            }

            .info-box li {
                margin-bottom: 0.25rem;
            }

            .instruction-box {
                background-color: #e8f4fd;
                border: 1px solid #bee5eb;
                border-radius: 0.375rem;
                padding: 1rem;
                margin-bottom: 1.5rem;
            }

            .instruction-box h4 {
                color: #0c5460;
                margin-bottom: 0.75rem;
                font-size: 1.25rem;
                font-weight: bold;
            }

            .instruction-box h5 {
                color: #0c5460;
                margin-top: 1rem;
                margin-bottom: 0.5rem;
                font-size: 1rem;
                font-weight: bold;
            }

            .instruction-box p,
            .instruction-box ul {
                color: #0c5460;
                margin-bottom: 0.5rem;
            }

            .instruction-box ul {
                list-style-type: disc;
                margin-left: 1.5rem;
                padding-left: 0;
            }

            .instruction-box li {
                margin-bottom: 0.25rem;
            }

            .instruction-box .system-actions {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 1rem;
                margin-top: 1rem;
                margin-bottom: 1.5rem;
            }

            .instruction-box .action-item {
                background-color: #d1ecf1;
                border: 1px solid #bee5eb;
                border-radius: 0.375rem;
                padding: 1rem;
                display: flex;
                flex-direction: column;
            }

            .instruction-box .action-item-title {
                font-weight: bold;
                color: #0c5460;
                margin-bottom: 0.5rem;
                font-size: 1rem;
            }

            .instruction-box .action-item-desc {
                font-size: 0.9rem;
                color: #0c5460;
                line-height: 1.4;
            }

            .instruction-box .arrow {
                font-weight: bold;
                color: #0c5460;
            }

            .instruction-box .important-notes,
            .instruction-box .quick-tips {
                margin-top: 1.5rem;
                padding-top: 1rem;
                border-top: 1px solid #bee5eb;
            }

            .file-upload-section .customer-form-group {
                margin-bottom: 1.5rem;
            }
            </style>
</body>

</html>
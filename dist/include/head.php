  <!-- [Head] start -->
    <!-- [Meta] -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta
      name="description"
      content="FEIT Solutions Order Management System is a modern dashboard built to manage orders, inventory, customers, and business operations efficiently."
    />

    <meta
      name="keywords"
      content="FEIT Solutions, Order Management System, orderhub_nextwave dashboard, order tracking system, inventory management, business dashboard, admin panel"
    />

    <meta name="author" content="FEIT Solutions" />

    <!-- [Favicon] icon -->
    <?php
    $favicon_url = '';
    if (isset($conn) && $conn) {
        try {
            $user_tenant_id = $_SESSION['tenant_id'] ?? null;
            if ($user_tenant_id) {
                $fav_query = "SELECT fav_icon_url FROM tenants WHERE tenant_id = " . (int)$user_tenant_id . " AND status = 'active' AND fav_icon_url IS NOT NULL AND fav_icon_url != '' LIMIT 1";
            } else {
                $fav_query = "SELECT fav_icon_url FROM tenants WHERE status = 'active' AND fav_icon_url IS NOT NULL AND fav_icon_url != '' LIMIT 1";
            }
            $fav_result = $conn->query($fav_query);
            if ($fav_result && $fav_result->num_rows > 0) {
                $fav_data = $fav_result->fetch_assoc();
                $favicon_url = $fav_data['fav_icon_url'];
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }
    if ($favicon_url) echo '<link rel="icon" href="' . htmlspecialchars($favicon_url) . '" type="image/x-icon" />';
    ?>

     <!-- [Font] Family -->
     <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600&display=swap" rel="stylesheet" />
    <!-- [phosphor Icons] https://phosphoricons.com/ -->
    <link rel="stylesheet" href="../assets/fonts/phosphor/duotone/style.css" />
    <!-- [Tabler Icons] https://tablericons.com -->
    <link rel="stylesheet" href="../assets/fonts/tabler-icons.min.css" />
    <!-- [Feather Icons] https://feathericons.com -->
    <link rel="stylesheet" href="../assets/fonts/feather.css" />
    <!-- [Font Awesome Icons] https://fontawesome.com/icons -->
    <link rel="stylesheet" href="../assets/fonts/fontawesome.css" />
    <!-- [Material Icons] https://fonts.google.com/icons -->
    <link rel="stylesheet" href="../assets/fonts/material.css" />
    <!-- [SweetAlert2]-->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/message.css" />
    <link rel="stylesheet" href="../assets/css/status-badge-colors.css" />
    <!-- [Global Responsive Styles] -->
    <link rel="stylesheet" href="../assets/css/responsive.css" />
    <style>html{overflow-y:scroll}</style>

  </head>
  <!-- [Head] end -->
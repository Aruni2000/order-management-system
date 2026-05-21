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
      content="FEIT Solutions, Order Management System, OMS dashboard, order tracking system, inventory management, business dashboard, admin panel"
    />

    <meta name="author" content="FEIT Solutions" />

    <!-- [Favicon] icon -->
    <?php
    // Default favicon
    $favicon_url = '../assets/images/favicon.png';
    
    // Check if we have a database connection to fetch custom favicon
    if (isset($conn) && $conn) {
        try {
            $check_branding_query = "SELECT fav_icon_url FROM branding WHERE active = 1 LIMIT 1";
            $branding_result = $conn->query($check_branding_query);
            
            if ($branding_result && $branding_result->num_rows > 0) {
                $branding_data = $branding_result->fetch_assoc();
                if (!empty($branding_data['fav_icon_url'])) {
                    $favicon_url = $branding_data['fav_icon_url'];
                }
            }
        } catch (Throwable $e) {
            // Silently fail and use default if DB error
        }
    }
    ?>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_url); ?>" type="image/x-icon" />

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
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />

  </head>
  <!-- [Head] end -->
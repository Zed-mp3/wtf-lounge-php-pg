<link rel="stylesheet" href="../css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../css/style.css') ?>">
</head>
<body>

<!-- Mobile: same navbar + slide-out menu as the customer site (hidden on desktop) -->
<header class="navbar admin-mobile-bar">
  <div class="container navbar__row">
    <button class="hamburger" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="sideNav">
      <span></span><span></span><span></span>
    </button>

    <a href="index.php" class="navbar__brand">WTF Lounge</a>

    <div class="navbar__right">
      <div class="account-menu">
        <a href="../logout.php">Log out</a>
      </div>
    </div>
  </div>
</header>

<div class="side-nav-backdrop" id="sideNavBackdrop"></div>
<nav class="side-nav admin-side-nav" id="sideNav" aria-hidden="true">
  <div class="side-nav__head">
    <span class="tagline-cursive">WTF Lounge</span>
    <button class="side-nav__close" id="navClose" aria-label="Close menu">&times;</button>
  </div>
  <a href="index.php" class="<?= $activeAdminNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
  <a href="orders.php" class="<?= $activeAdminNav === 'orders' ? 'active' : '' ?>">Orders</a>
  <a href="users.php" class="<?= $activeAdminNav === 'users' ? 'active' : '' ?>">Users</a>
  <a href="menu.php" class="<?= $activeAdminNav === 'menu' ? 'active' : '' ?>">Menu</a>
  <a href="meal_options.php" class="<?= $activeAdminNav === 'meal_options' ? 'active' : '' ?>">Meal Options</a>
  <a href="reports.php" class="<?= $activeAdminNav === 'reports' ? 'active' : '' ?>">Reports</a>
  <hr class="side-nav__divider">
  <div class="side-nav__account">Signed in as <?= h($_SESSION['fullname'] ?? 'Admin') ?></div>
  <a href="profile.php" class="<?= $activeAdminNav === 'profile' ? 'active' : '' ?>">My Profile</a>
  <a href="../menu.php">Back to Site</a>
  <a href="../logout.php">Log out</a>
</nav>

<!-- Admin Layout -->
<div class="admin-layout">

  <!-- Admin Sidebar (desktop only) -->
  <aside class="admin-sidebar">
    <div class="admin-sidebar__brand">
      <a href="index.php">WTF Lounge</a>
      <small>Admin Panel</small>
    </div>
    <nav class="admin-sidebar__nav">

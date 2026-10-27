<?php
/**
 * Admin page shell. Expects $pageTitle and $activeAdminNav to be set
 * by the including page. Assumes requireAdmin() already ran.
 */
$pageTitle = $pageTitle ?? 'Admin — WTF Lounge';
$activeAdminNav = $activeAdminNav ?? '';
$flash = flashGet();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body>

<!-- Admin Layout -->
<div class="admin-layout">
  
  <!-- Admin Sidebar -->
  <aside class="admin-sidebar">
    <div class="admin-sidebar__brand">
      <a href="index.php">WTF Lounge</a>
      <small>Admin Panel</small>
    </div>
    <nav class="admin-sidebar__nav">
      <a href="index.php" class="<?= $activeAdminNav === 'dashboard' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 3v18h18"/>
          <path d="M18 17V9"/>
          <path d="M13 17V5"/>
          <path d="M8 17v-3"/>
        </svg>
        Dashboard
      </a>
      <a href="orders.php" class="<?= $activeAdminNav === 'orders' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
          <path d="M3 6h18"/>
          <path d="M16 10a4 4 0 0 1-8 0"/>
        </svg>
        Orders
      </a>
      <a href="users.php" class="<?= $activeAdminNav === 'users' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
        Users
      </a>
      <a href="menu.php" class="<?= $activeAdminNav === 'menu' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 6h16"/>
          <path d="M4 12h16"/>
          <path d="M4 18h16"/>
        </svg>
        Menu
      </a>
      <a href="meal_options.php" class="<?= $activeAdminNav === 'meal_options' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 11h18"/>
          <path d="M5 11a7 7 0 0 1 14 0"/>
          <path d="M4 15h16l-1.5 4h-13z"/>
        </svg>
        Meal Options
      </a>
      <a href="reports.php" class="<?= $activeAdminNav === 'reports' ? 'active' : '' ?>">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/>
          <path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/>
          <path d="M18 17a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
        </svg>
        Reports
      </a>
    </nav>
    <div class="admin-sidebar__footer">
      <a href="../menu.php">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 12h18"/>
          <path d="M18 5l7 7-7 7"/>
        </svg>
        Back to Site
      </a>
      <a href="../logout.php" style="margin-top: 8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        Log Out
      </a>
    </div>
  </aside>

  <!-- Admin Main Content -->
  <main class="admin-main">
    <?php if ($flash): ?>
      <div class="flash-msg" style="margin-bottom: 16px;"><?= h($flash) ?></div>
    <?php endif; ?>
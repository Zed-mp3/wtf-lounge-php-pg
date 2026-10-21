<?php
/**
 * Shared page shell (top half). Expects $pageTitle to be set by the
 * including page. $activeNav (optional) highlights a nav link.
 * Assumes configureSession()/auth.php/functions.php already loaded.
 */
$pageTitle = $pageTitle ?? 'WTF Lounge';
$activeNav = $activeNav ?? '';
$cartCount = cartCount();
$flash = flashGet();
$loggedIn = isLoggedIn();
$admin = isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="css/style.css">
<script src="js/main.js" defer></script>
<script src="js/ui-handlers.js" defer></script>
</head>
<body>
<div class="page">

  <header class="navbar">
    <div class="container navbar__row">
      <button class="hamburger" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="sideNav">
        <span></span><span></span><span></span>
      </button>

      <a href="menu.php" class="navbar__brand">WTF Lounge</a>

      <nav class="navbar__links">
        <a href="menu.php" class="<?= $activeNav === 'menu' ? 'active' : '' ?>">Menu</a>
        <a href="orders.php" class="<?= $activeNav === 'orders' ? 'active' : '' ?>">Orders</a>
        <?php if ($admin): ?>
          <a href="admin/index.php" class="<?= $activeNav === 'admin' ? 'active' : '' ?>">Admin</a>
        <?php endif; ?>
      </nav>

      <div class="navbar__right">
        <?php if ($loggedIn): ?>
          <div class="account-menu">
            <span class="account-menu__name">Hi, <?= h($_SESSION['fullname']) ?></span>
            <span class="account-menu__divider">|</span>
            <a href="logout.php">Log out</a>
          </div>
        <?php else: ?>
          <!-- Login/Register links removed from navbar -->
        <?php endif; ?>
        <a href="cart.php" class="cart-icon" aria-label="View cart">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <span class="cart-icon__badge"><?= (int) $cartCount ?></span>
        </a>
      </div>
    </div>
  </header>

  <!-- Off-canvas side nav for mobile -->
  <div class="side-nav-backdrop" id="sideNavBackdrop"></div>
  <nav class="side-nav" id="sideNav" aria-hidden="true">
    <div class="side-nav__head">
      <span class="tagline-cursive">WTF Lounge</span>
      <button class="side-nav__close" id="navClose" aria-label="Close menu">&times;</button>
    </div>
    <a href="menu.php" class="<?= $activeNav === 'menu' ? 'active' : '' ?>">Menu</a>
    <a href="orders.php" class="<?= $activeNav === 'orders' ? 'active' : '' ?>">Orders</a>
    <a href="cart.php" class="<?= $activeNav === 'cart' ? 'active' : '' ?>">Cart (<?= (int) $cartCount ?>)</a>
    <?php if ($admin): ?>
      <a href="admin/index.php" class="<?= $activeNav === 'admin' ? 'active' : '' ?>">Admin Dashboard</a>
    <?php endif; ?>
    <hr class="side-nav__divider">
    <?php if ($loggedIn): ?>
      <div class="side-nav__account">Signed in as <?= h($_SESSION['fullname']) ?></div>
      <a href="logout.php">Log out</a>
    <?php else: ?>
      <a href="login.php">Log in</a>
      <a href="register.php">Register</a>
    <?php endif; ?>
  </nav>

  <?php if ($flash): ?>
    <div class="container" style="padding-top:16px;">
      <div class="flash-msg"><?= h($flash) ?></div>
    </div>
  <?php endif; ?>

  <main class="main">
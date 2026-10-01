<?php
/**
 * Admin footer - closes the admin layout
 */
?>
  </main>
</div>

<!-- Mobile menu toggle for admin sidebar -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // For mobile: toggle sidebar if needed
  const sidebar = document.querySelector('.admin-sidebar');
  if (sidebar && window.innerWidth <= 768) {
    // Sidebar is already visible in mobile layout
  }
});

// Handle responsive sidebar toggle (optional)
(function () {
  var toggle = document.getElementById('navToggle');
  var closeBtn = document.getElementById('navClose');
  var sideNav = document.getElementById('sideNav');
  var backdrop = document.getElementById('sideNavBackdrop');

  function openNav() {
    if (sideNav) {
      sideNav.classList.add('is-open');
      sideNav.setAttribute('aria-hidden', 'false');
    }
    if (backdrop) {
      backdrop.classList.add('is-open');
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
    }
    document.body.style.overflow = 'hidden';
  }
  
  function closeNav() {
    if (sideNav) {
      sideNav.classList.remove('is-open');
      sideNav.setAttribute('aria-hidden', 'true');
    }
    if (backdrop) {
      backdrop.classList.remove('is-open');
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
    document.body.style.overflow = '';
  }
  
  if (toggle) { toggle.addEventListener('click', openNav); }
  if (closeBtn) { closeBtn.addEventListener('click', closeNav); }
  if (backdrop) { backdrop.addEventListener('click', closeNav); }
  
  document.addEventListener('keydown', function (e) { 
    if (e.key === 'Escape') closeNav(); 
  });
})();
</script>
</body>
</html>
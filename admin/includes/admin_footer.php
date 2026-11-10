<?php
/**
 * Admin footer - closes the admin layout and wires the mobile drawer.
 */
?>
  </main>
</div>

<script>
(function () {
  var toggle = document.getElementById('navToggle');
  var closeBtn = document.getElementById('navClose');
  var sideNav = document.getElementById('sideNav');
  var backdrop = document.getElementById('sideNavBackdrop');

  function setOpen(open) {
    if (sideNav) sideNav.classList.toggle('is-open', open);
    if (backdrop) backdrop.classList.toggle('is-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (toggle) toggle.addEventListener('click', function () { setOpen(true); });
  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
  if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  // Leaving mobile width (e.g. rotating a tablet) should never leave the page locked.
  window.addEventListener('resize', function () { if (window.innerWidth > 768) setOpen(false); });
})();
</script>
</body>
</html>

<?php
/**
 * Shared "My Profile" logic + markup, used by profile.php (customers)
 * and admin/profile.php (admins). Requires auth.php, config/db.php and
 * functions.php to be loaded and the user to be logged in.
 */

/**
 * Handles profile / password POSTs. On success it sets a flash message and
 * redirects to $redirect. On failure it returns ['profile' => msg|null, 'password' => msg|null].
 */
function handleProfilePost(PDO $pdo, string $redirect): array
{
    $errors = ['profile' => null, 'password' => null];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $errors;
    }

    csrfVerify();
    $uid = (int) currentUserId();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            try {
                updateOwnProfile($pdo, $uid, $_POST);
            } catch (PDOException $e) {
                error_log('Profile update failed: ' . $e->getMessage());
                throw new RuntimeException('Could not save your profile. Please try again.');
            }
            flashSet('Profile updated.');
            header('Location: ' . $redirect);
            exit;
        }

        if ($action === 'change_password') {
            changeOwnPassword(
                $pdo,
                $uid,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );
            flashSet('Password changed.');
            header('Location: ' . $redirect);
            exit;
        }
    } catch (RuntimeException $e) {
        $key = $action === 'change_password' ? 'password' : 'profile';
        $errors[$key] = $e->getMessage();
    }

    return $errors;
}

/** Prints the profile + change-password cards. $action is the form target (e.g. 'profile.php'). */
function renderProfileCards(array $customer, array $errors, string $action): void
{
    $isPostedProfile = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'update_profile');
    $val = function (string $key) use ($customer, $isPostedProfile) {
        return $isPostedProfile ? (string) ($_POST[$key] ?? '') : (string) ($customer[$key] ?? '');
    };
    $hasEmail = !empty($customer['email']);
    ?>
    <section class="profile-card">
      <div class="profile-card__head">
        <div class="profile-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $customer['fullname'], 0, 1))) ?></div>
        <div>
          <h2 class="profile-card__title"><?= h($customer['fullname']) ?></h2>
          <p class="profile-card__meta">
            <span class="profile-role"><?= !empty($customer['is_admin']) ? 'Admin' : 'Customer' ?></span>
            <?php if (!empty($customer['created_at'])): ?>
              &middot; Member since <?= h(date('M j, Y', strtotime($customer['created_at']))) ?>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <?php if ($errors['profile']): ?>
        <div class="form-errors"><?= h($errors['profile']) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= h($action) ?>" class="profile-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="field">
          <label for="pf_fullname">Full Name</label>
          <input type="text" id="pf_fullname" name="fullname" required value="<?= h($val('fullname')) ?>">
        </div>
        <div class="field">
          <label for="pf_phone">Phone Number (used to log in)</label>
          <input type="tel" id="pf_phone" name="phone" required value="<?= h($val('phone')) ?>">
        </div>
        <div class="field">
          <label for="pf_email">Email Address</label>
          <?php if ($hasEmail): ?>
            <input type="email" id="pf_email" value="<?= h($customer['email']) ?>" readonly aria-describedby="pf_email_note">
            <small id="pf_email_note">Your email is verified and used for password recovery, so it can't be changed here.</small>
          <?php else: ?>
            <input type="email" id="pf_email" name="email" value="<?= h($val('email')) ?>" placeholder="e.g., juan@gmail.com" autocomplete="email">
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="pf_address">Address</label>
          <textarea id="pf_address" name="address" required><?= h($val('address')) ?></textarea>
        </div>
        <button type="submit" class="btn btn--primary">Save Changes</button>
      </form>
    </section>

    <section class="profile-card">
      <h2 class="profile-card__title">Change Password</h2>

      <?php if ($errors['password']): ?>
        <div class="form-errors"><?= h($errors['password']) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= h($action) ?>" class="profile-form" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="field">
          <label for="pf_current">Current Password</label>
          <input type="password" id="pf_current" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field">
          <label for="pf_new">New Password</label>
          <input type="password" id="pf_new" name="new_password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="pf_confirm">Confirm New Password</label>
          <input type="password" id="pf_confirm" name="confirm_password" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn--secondary">Update Password</button>
      </form>
    </section>
    <?php
}

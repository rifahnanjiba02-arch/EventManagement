<?php
require_once 'session_bootstrap.php';
require_once 'db.php';

$allowedRoles = ['attendee', 'organizer'];
$errors = [];
$formData = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone_number1' => '',
    'phone_number2' => '',
    'phone_number3' => '',
    'role' => ''
];

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'organizer') {
        header('Location: organizer_profile.php');
    } else {
        header('Location: attendee_profile.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $field => $value) {
        $formData[$field] = trim($_POST[$field] ?? '');
    }

    $password = $_POST['password'] ?? '';
    $phoneNumbers = [
        $formData['phone_number1'],
        $formData['phone_number2'],
        $formData['phone_number3']
    ];

    if ($formData['first_name'] === '' || $formData['last_name'] === '' || $formData['email'] === '' || $password === '' || $formData['role'] === '') {
        $errors[] = 'Complete all required fields before creating your account.';
    }

    if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'Use at least 8 characters for your password.';
    }

    if ($formData['role'] !== '' && !in_array($formData['role'], $allowedRoles, true)) {
        $errors[] = 'Select a valid account type.';
    }

    $validPhones = [];
    foreach ($phoneNumbers as $phone) {
        if ($phone === '') {
            continue;
        }

        if (!preg_match('/^\d{11}$/', $phone)) {
            $errors[] = 'Phone numbers must contain exactly 11 digits.';
            break;
        }

        $validPhones[] = $phone;
    }

    if (count($validPhones) === 0) {
        $errors[] = 'Add at least one phone number.';
    }

    if (count(array_unique($validPhones)) !== count($validPhones)) {
        $errors[] = 'Each phone number should be unique.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM Users WHERE email = ?');
            $stmt->execute([$formData['email']]);

            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = 'This email address is already registered.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $phone1 = $formData['phone_number1'] !== '' ? $formData['phone_number1'] : null;
                $phone2 = $formData['phone_number2'] !== '' ? $formData['phone_number2'] : null;
                $phone3 = $formData['phone_number3'] !== '' ? $formData['phone_number3'] : null;

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO Users (
                        first_name,
                        last_name,
                        email,
                        phone_number1,
                        phone_number2,
                        phone_number3,
                        password_hash,
                        role
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $formData['first_name'],
                    $formData['last_name'],
                    $formData['email'],
                    $phone1,
                    $phone2,
                    $phone3,
                    $hashedPassword,
                    $formData['role']
                ]);

                $userId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO User_Profile (user_id, bio, profile_picture, social_link1, social_link2, social_link3)
                     VALUES (?, '', NULL, NULL, NULL, NULL)"
                );
                $stmt->execute([$userId]);

                if ($formData['role'] === 'organizer') {
                    $stmt = $pdo->query('SELECT COALESCE(MAX(organizer_id), 1000) + 1 FROM Organizer');
                    $organizerId = (int) $stmt->fetchColumn();

                    $stmt = $pdo->prepare('INSERT INTO Organizer (user_id, organizer_id, is_admin) VALUES (?, ?, 0)');
                    $stmt->execute([$userId, $organizerId]);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['role'] = 'organizer';
                    $_SESSION['organizer_id'] = $organizerId;
                    $_SESSION['attendee_id'] = null;
                    $_SESSION['is_admin'] = 0;
                } else {
                    $stmt = $pdo->query('SELECT COALESCE(MAX(attendee_id), 2000) + 1 FROM Attendee');
                    $attendeeId = (int) $stmt->fetchColumn();

                    $stmt = $pdo->prepare('INSERT INTO Attendee (user_id, attendee_id) VALUES (?, ?)');
                    $stmt->execute([$userId, $attendeeId]);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['role'] = 'attendee';
                    $_SESSION['attendee_id'] = $attendeeId;
                    $_SESSION['organizer_id'] = null;
                    $_SESSION['is_admin'] = 0;
                }

                $pdo->commit();
                if ($formData['role'] === 'organizer') {
                    header('Location: organizer_profile.php');
                } else {
                    header('Location: attendee_profile.php');
                }
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'We could not create your account right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Account | Event Booking</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bg: #eef4f8;
      --surface: rgba(255, 255, 255, 0.92);
      --surface-strong: #ffffff;
      --text: #132238;
      --muted: #5f7188;
      --line: rgba(19, 34, 56, 0.12);
      --brand: #0f766e;
      --brand-deep: #134e4a;
      --accent: #d97706;
      --shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
      --radius: 30px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Manrope", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top right, rgba(15, 118, 110, 0.16), transparent 26%),
        radial-gradient(circle at bottom left, rgba(217, 119, 6, 0.16), transparent 20%),
        linear-gradient(180deg, #f9fbfd 0%, var(--bg) 100%);
    }

    .auth-shell {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .auth-card {
      width: min(1160px, 100%);
      border: 1px solid rgba(255, 255, 255, 0.72);
      border-radius: var(--radius);
      overflow: hidden;
      background: var(--surface);
      backdrop-filter: blur(16px);
      box-shadow: var(--shadow);
    }

    .auth-side {
      padding: 42px 38px;
      background:
        linear-gradient(180deg, #fffdf8 0%, #f7fbfc 100%);
      border-right: 1px solid rgba(19, 34, 56, 0.06);
      height: 100%;
    }

    .auth-side h1 {
      margin: 20px 0 12px;
      font-size: clamp(2rem, 3vw, 2.7rem);
      font-weight: 800;
      line-height: 1.08;
    }

    .auth-side p {
      color: var(--muted);
      margin-bottom: 28px;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      background: rgba(15, 118, 110, 0.08);
      color: var(--brand-deep);
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .auth-points {
      display: grid;
      gap: 14px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .auth-points li {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 14px 16px;
      background: #fff;
      border: 1px solid rgba(19, 34, 56, 0.06);
      border-radius: 18px;
    }

    .point-badge {
      width: 34px;
      height: 34px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(15, 118, 110, 0.12);
      color: var(--brand-deep);
      font-weight: 800;
      flex-shrink: 0;
    }

    .auth-form-wrap {
      padding: 42px 38px;
    }

    .auth-form-wrap h2 {
      margin: 0 0 10px;
      font-size: 1.95rem;
      font-weight: 800;
    }

    .auth-form-wrap p {
      color: var(--muted);
      margin-bottom: 24px;
    }

    .form-label {
      font-weight: 700;
      margin-bottom: 8px;
    }

    .form-control,
    .form-select {
      border-radius: 16px;
      border: 1px solid var(--line);
      min-height: 54px;
      padding: 0.9rem 1rem;
      background: var(--surface-strong);
      box-shadow: none;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: rgba(15, 118, 110, 0.45);
      box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.12);
    }

    .role-picker {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .role-option input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .role-card {
      display: block;
      height: 100%;
      padding: 18px 18px 16px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.94);
      cursor: pointer;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
    }

    .role-card strong {
      display: block;
      margin-bottom: 6px;
      font-size: 1rem;
    }

    .role-card span {
      display: block;
      color: var(--muted);
      font-size: 0.94rem;
      line-height: 1.45;
    }

    .role-option input:checked + .role-card {
      border-color: rgba(15, 118, 110, 0.65);
      box-shadow: 0 0 0 0.2rem rgba(15, 118, 110, 0.12);
      transform: translateY(-1px);
      background: linear-gradient(180deg, rgba(240, 253, 250, 0.98), rgba(255, 255, 255, 0.98));
    }

    .role-option input:focus-visible + .role-card {
      box-shadow: 0 0 0 0.24rem rgba(15, 118, 110, 0.16);
    }

    .form-text {
      color: var(--muted);
    }

    .btn-auth {
      width: 100%;
      min-height: 56px;
      border: 0;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--brand), var(--brand-deep));
      color: #fff;
      font-weight: 800;
      letter-spacing: 0.01em;
      box-shadow: 0 18px 30px rgba(19, 78, 74, 0.18);
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .btn-auth:hover {
      transform: translateY(-1px);
      box-shadow: 0 22px 34px rgba(19, 78, 74, 0.22);
      color: #fff;
    }

    .inline-link {
      color: var(--brand-deep);
      font-weight: 700;
      text-decoration: none;
    }

    .inline-link:hover {
      text-decoration: underline;
    }

    .auth-footer {
      margin-top: 20px;
      color: var(--muted);
      text-align: center;
    }

    .alert {
      border: 0;
      border-radius: 18px;
    }

    @media (max-width: 991.98px) {
      .auth-side,
      .auth-form-wrap {
        padding: 32px 24px;
      }

      .auth-side {
        border-right: 0;
        border-bottom: 1px solid rgba(19, 34, 56, 0.06);
      }
    }

    @media (max-width: 575.98px) {
      .role-picker {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <div class="row g-0">
        <div class="col-lg-5">
          <div class="auth-side">
            <span class="eyebrow">Create Your Account</span>
            <h1>Join Event Booking with a cleaner signup experience.</h1>
            <p>Set up your attendee or organizer account with a form that keeps your input, explains validation clearly, and sends you straight into the proper sign-in flow.</p>
            <ul class="auth-points">
              <li><span class="point-badge">1</span><span>Add your contact details once and keep the profile ready for later updates.</span></li>
              <li><span class="point-badge">2</span><span>Choose the right account type so the platform opens the correct workspace after login.</span></li>
              <li><span class="point-badge">3</span><span>Use one primary phone number now, then keep optional backups if you need them.</span></li>
            </ul>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="auth-form-wrap">
            <h2>Create Account</h2>
            <p>Fill in your details to get started.</p>

            <?php if (!empty($errors)): ?>
              <div class="alert alert-danger mb-4" role="alert">
                <?php foreach ($errors as $error): ?>
                  <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form action="register.php" method="POST" novalidate>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="first_name" class="form-label">First Name</label>
                  <input type="text" name="first_name" id="first_name" class="form-control" required value="<?= htmlspecialchars($formData['first_name']) ?>">
                </div>

                <div class="col-md-6 mb-3">
                  <label for="last_name" class="form-label">Last Name</label>
                  <input type="text" name="last_name" id="last_name" class="form-control" required value="<?= htmlspecialchars($formData['last_name']) ?>">
                </div>
              </div>

              <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control" autocomplete="email" required value="<?= htmlspecialchars($formData['email']) ?>">
              </div>

              <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control" autocomplete="new-password" required>
                <div class="form-text">Use at least 8 characters.</div>
              </div>

              <div class="mb-4">
                <label class="form-label d-block">Account Type</label>
                <div class="role-picker" role="radiogroup" aria-label="Account Type">
                  <label class="role-option">
                    <input type="radio" name="role" value="attendee" <?= $formData['role'] === 'attendee' ? 'checked' : '' ?> required>
                    <span class="role-card">
                      <strong>Attendee</strong>
                      <span>Book events, manage your attendee profile, and keep your activity in one place.</span>
                    </span>
                  </label>
                  <label class="role-option">
                    <input type="radio" name="role" value="organizer" <?= $formData['role'] === 'organizer' ? 'checked' : '' ?> required>
                    <span class="role-card">
                      <strong>Organizer</strong>
                      <span>Publish events, manage operations, and coordinate attendees from your dashboard.</span>
                    </span>
                  </label>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="phone_number1" class="form-label">Primary Phone Number</label>
                  <input type="text" name="phone_number1" id="phone_number1" class="form-control" inputmode="numeric" maxlength="11" placeholder="11 digits" value="<?= htmlspecialchars($formData['phone_number1']) ?>">
                </div>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="phone_number2" class="form-label">Backup Phone Number</label>
                  <input type="text" name="phone_number2" id="phone_number2" class="form-control" inputmode="numeric" maxlength="11" placeholder="Optional" value="<?= htmlspecialchars($formData['phone_number2']) ?>">
                </div>

                <div class="col-md-6 mb-4">
                  <label for="phone_number3" class="form-label">Additional Phone Number</label>
                  <input type="text" name="phone_number3" id="phone_number3" class="form-control" inputmode="numeric" maxlength="11" placeholder="Optional" value="<?= htmlspecialchars($formData['phone_number3']) ?>">
                </div>
              </div>

              <button type="submit" class="btn btn-auth">Create Account</button>
            </form>

            <div class="auth-footer">
              Already registered? <a class="inline-link" href="login.php">Go to login</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>

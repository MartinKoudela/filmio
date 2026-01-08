<?php
session_start();

require_once __DIR__ . '/db_connect.php';

// Redirect already logged-in members to dashboard
if (isset($_SESSION['member_id'])) {
    header('Location: dashboard.php');
    exit;
}

$successMessage = '';
$errorMessage = '';

// Check for session timeout message
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $errorMessage = 'Your session has expired. Please sign in again.';
}

// Ensure password column exists in the database
$columnCheck = $conn->query("SHOW COLUMNS FROM `člen` LIKE 'heslo'");
if ($columnCheck && $columnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE `člen` ADD COLUMN `heslo` VARCHAR(255) DEFAULT NULL");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $firstName  = trim($_POST['first_name'] ?? '');
        $lastName   = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $membership = trim($_POST['membership'] ?? 'Member');
        $address    = trim($_POST['address'] ?? '');
        $regDate    = date('Y-m-d');

        if ($firstName === '' || $email === '') {
            $errorMessage = 'Please fill in at least your first name and email to register.';
        } elseif (strlen($password) < 6) {
            $errorMessage = 'Password must be at least 6 characters long.';
        } else {
            // Check if the member already exists
            $stmt = $conn->prepare('SELECT `ID_člen` FROM `člen` WHERE `email` = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->bind_result($existingId);
            $stmt->fetch();
            $stmt->close();

            if ($existingId) {
                $errorMessage = 'An account with this email already exists. Please sign in instead.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                        'INSERT INTO `člen` (`jméno`, `příjmení`, `email`, `heslo`, `adresa`, `datum_registrace`, `členství`) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssssss', $firstName, $lastName, $email, $hashedPassword, $address, $regDate, $membership);
                $stmt->execute();
                $stmt->close();

                $successMessage = 'Registration successful! You can now sign in.';
            }
        }
    }

    if ($action === 'login') {
        $loginEmail = trim($_POST['login_email'] ?? '');
        $loginPassword = $_POST['login_password'] ?? '';

        if ($loginEmail === '' || $loginPassword === '') {
            $errorMessage = 'Please provide both your email and password to sign in.';
        } else {
            $stmt = $conn->prepare('SELECT `ID_člen`, `jméno`, `příjmení`, `heslo`, `členství` FROM `člen` WHERE `email` = ? LIMIT 1');
            $stmt->bind_param('s', $loginEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $member = $result->fetch_assoc();
            $stmt->close();

            if ($member) {
                // Check if password is set (for legacy accounts without password)
                if (empty($member['heslo'])) {
                    $errorMessage = 'Please contact administrator to set up your password.';
                } elseif (password_verify($loginPassword, $member['heslo'])) {
                    $_SESSION['member_id'] = (int)$member['ID_člen'];
                    $_SESSION['member_name'] = trim($member['jméno'] . ' ' . $member['příjmení']);
                    $_SESSION['last_activity'] = time();
                    $isAdmin = isset($member['členství']) && strcasecmp($member['členství'], 'Admin') === 0;
                    $_SESSION['is_admin'] = $isAdmin;

                    header('Location: ' . ($isAdmin ? 'adminMenu.php' : 'dashboard.php'));
                    exit;
                } else {
                    $errorMessage = 'Invalid email or password. Please try again.';
                }
            } else {
                $errorMessage = 'Invalid email or password. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en" class="bg-slate-950 text-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filmio | Sign in</title>
    <link rel="icon" type="image/x-icon" href="/favicon.png">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-950">
<div class="mx-auto flex max-w-5xl flex-col gap-10 px-6 py-12 lg:flex-row">
    <section class="flex-1 space-y-6 rounded-3xl bg-white/5 p-8 ring-1 ring-white/10 backdrop-blur">
        <header class="space-y-3">
            <p class="text-sm uppercase tracking-[0.25em] text-slate-400">Film Club</p>
            <h1 class="text-3xl font-semibold text-white">Welcome to Filmio</h1>
            <p class="text-slate-300">Sign in or create a member profile to access the dashboard and upcoming screenings.</p>
        </header>

        <?php if ($errorMessage): ?>
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-red-100"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-emerald-100"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">First name</span>
                    <input name="first_name" required placeholder="Ava" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Last name</span>
                    <input name="last_name" placeholder="Chen" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                    <span class="block">Email</span>
                    <input name="email" type="email" required placeholder="ava@example.com" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                    <span class="block">Password</span>
                    <input name="password" type="password" required minlength="6" placeholder="Min. 6 characters" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Membership</span>
                    <input name="membership" placeholder="Member / Host" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Address</span>
                    <input name="address" placeholder="City, Country" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
            </div>
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                Create profile
            </button>
            <input type="hidden" name="action" value="register">
        </form>
    </section>

    <section class="w-full max-w-md space-y-9 rounded-3xl bg-white/5 p-8 ring-1 ring-white/10 backdrop-blur">
        <header class="space-y-2">
            <h2 class="text-2xl font-semibold text-white">Sign in</h2>
            <p class="text-sm text-slate-400">Existing members can access the dashboard using their email and password.</p>
        </header>
        <form method="post" class="space-y-4">
            <label class="text-sm text-slate-300">
                <span class="block">Email</span>
                <input name="login_email" type="email" required placeholder="ava@example.com" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
            </label>

            <label class="text-sm text-slate-300">
                <span class="block">Password</span>
                <input name="login_password" type="password" required placeholder="Your password" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
            </label>
            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-400 mt-4 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-indigo-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-200">
                Access dashboard
            </button>
            <input type="hidden" name="action" value="login">
        </form>


    </section>
</div>
</body>
</html>

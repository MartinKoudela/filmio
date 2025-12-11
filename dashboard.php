<?php
session_start();

require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

$memberId = (int)$_SESSION['member_id'];
$memberName = $_SESSION['member_name'] ?? '';
$isAdmin = !empty($_SESSION['is_admin']);

$alertMessage = '';

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Ensure join request table exists
$conn->query(
        'CREATE TABLE IF NOT EXISTS `screening_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ID_promítání` INT NOT NULL,
        `ID_člen` INT NOT NULL,
        `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_request` (`ID_promítání`, `ID_člen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// Handle join request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'join_screening') {
    $screeningId = (int)($_POST['screening_id'] ?? 0);

    if ($screeningId > 0) {
        $stmt = $conn->prepare('INSERT INTO `screening_requests` (`ID_promítání`, `ID_člen`) VALUES (?, ?)');
        $stmt->bind_param('ii', $screeningId, $memberId);

        if ($stmt->execute()) {
            $alertMessage = 'Join request sent for this screening.';
        } elseif ($stmt->errno === 1062) {
            $alertMessage = 'You have already requested to join this screening.';
        } else {
            $alertMessage = 'We could not submit your request. Please try again later.';
        }

        $stmt->close();
    } else {
        $alertMessage = 'Invalid screening selection.';
    }
}

// Fetch member profile
$stmt = $conn->prepare('SELECT `jméno`, `příjmení`, `email`, `členství`, `datum_registrace` FROM `člen` WHERE `ID_člen` = ? LIMIT 1');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$memberRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch films and screenings
$films = $conn->query('SELECT `název`, `režisér`, `rok_vydání`, `žánr` FROM `film` ORDER BY `název`')->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare(
        'SELECT s.`ID_promítání`, s.`datum`, s.`čas`, s.`místo`, f.`název` AS film_název ' .
        'FROM `sraz` s LEFT JOIN `film` f ON s.`ID_film` = f.`ID_film` ' .
        'ORDER BY s.`datum` ASC, s.`čas` ASC'
);
$stmt->execute();
$screenings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch existing join requests for the member
$stmt = $conn->prepare('SELECT `ID_promítání` FROM `screening_requests` WHERE `ID_člen` = ?');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$requestsResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$requestedScreeningIds = array_map(static fn($row) => (int)$row['ID_promítání'], $requestsResult);
?>
<!doctype html>
<html lang="en" class="bg-slate-950 text-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filmio | Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-950">
<header class="sticky top-0 z-10 border-b border-white/5 bg-slate-950/80 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <div>
            <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Film Club</p>
            <h1 class="text-xl font-semibold text-white">Member Dashboard</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <p class="text-sm font-medium text-white"><?= htmlspecialchars($memberName) ?></p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($memberRow['email'] ?? '') ?></p>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <button class="rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/15 hover:bg-white/20">
                    Log out
                </button>
            </form>
        </div>
    </div>
</header>

<main class="mx-auto flex max-w-6xl flex-col gap-8 px-6 py-10">
    <section class="grid gap-4 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur sm:grid-cols-3">
        <div class="rounded-xl bg-slate-900/60 p-4 ring-1 ring-white/10">
            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Membership</p>
            <p class="mt-2 text-xl font-semibold text-white"><?= htmlspecialchars($memberRow['členství'] ?? 'Member') ?></p>
            <p class="text-sm text-slate-400">Joined
                on <?= htmlspecialchars($memberRow['datum_registrace'] ?? '') ?></p>
        </div>
        <div class="rounded-xl bg-slate-900/60 p-4 ring-1 ring-white/10">
            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Films</p>
            <p class="mt-2 text-xl font-semibold text-white"><?= count($films) ?> in catalog</p>
            <p class="text-sm text-slate-400">Curated by your team.</p>
        </div>
        <div class="rounded-xl bg-slate-900/60 p-4 ring-1 ring-white/10">
            <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Screenings</p>
            <p class="mt-2 text-xl font-semibold text-white"><?= count($screenings) ?> scheduled</p>
            <p class="text-sm text-slate-400">Additions appear instantly.</p>
        </div>
    </section>

    <?php if ($alertMessage): ?>
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100 ring-1 ring-emerald-500/20">
            <?= htmlspecialchars($alertMessage) ?>
        </div>
    <?php endif; ?>

    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-lg font-semibold text-white">Catalog</h2>
                <p class="text-sm text-slate-400">Browse every film the club is tracking.</p>
            </div>
        </div>
        <div class="mt-4 overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="min-w-full text-left text-sm text-slate-200">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Director</th>
                    <th class="px-4 py-3">Year</th>
                    <th class="px-4 py-3">Genre</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($films): ?>
                    <?php foreach ($films as $film): ?>
                        <tr class="hover:bg-white/5 transition" onclick="window.location.href='ratings.php'">
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($film['název']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($film['režisér']) ?></td>
                            <td class="px-4 py-3"><?= (int)$film['rok_vydání'] ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($film['žánr']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-slate-400">No films have been added yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Page for users -->
    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-lg font-semibold text-white">Upcoming screenings</h2>
                <p class="text-sm text-slate-400">Dates and venues for your next meetups.</p>
            </div>
        </div>
        <div class="mt-4 overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="min-w-full text-left text-sm text-slate-200">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
                <tr>
                    <th class="px-4 py-3">Film</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Time</th>
                    <th class="px-4 py-3">Venue</th>
                    <th class="px-4 py-3 text-center">Join</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($screenings): ?>
                    <?php foreach ($screenings as $screening): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($screening['film_název'] ?? 'TBA') ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['datum']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['čas']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($screening['místo']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if (in_array((int)$screening['ID_promítání'], $requestedScreeningIds, true)): ?>
                                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-100 ring-1 ring-emerald-500/20">Requested</span>
                                <?php else: ?>
                                    <form method="post" class="inline-flex">
                                        <input type="hidden" name="action" value="join_screening">
                                        <input type="hidden" name="screening_id"
                                               value="<?= (int)$screening['ID_promítání'] ?>">
                                        <button class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white ring-1 ring-white/15 transition hover:bg-white/20"
                                                type="submit">Request
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-400">No screenings have been scheduled
                            yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
<?php
session_start();

require_once __DIR__ . '/db_connect.php';

// Check session timeout
checkSessionTimeout();

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

// Get filter parameters
$filterGenre = isset($_GET['genre']) ? trim($_GET['genre']) : '';
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterSort = isset($_GET['sort']) ? trim($_GET['sort']) : 'name';

// Fetch unique genres for filter dropdown
$genresResult = $conn->query("SELECT DISTINCT `žánr` FROM `film` WHERE `žánr` != '' ORDER BY `žánr`");
$genres = $genresResult ? $genresResult->fetch_all(MYSQLI_ASSOC) : [];

// Build films query with filters
$filmQuery = 'SELECT `ID_film`, `název`, `režisér`, `rok_vydání`, `žánr` FROM `film` WHERE 1=1';
$filmParams = [];
$filmTypes = '';

if ($filterGenre !== '') {
    $filmQuery .= ' AND `žánr` LIKE ?';
    $filmParams[] = '%' . $filterGenre . '%';
    $filmTypes .= 's';
}

if ($filterSearch !== '') {
    $filmQuery .= ' AND (`název` LIKE ? OR `režisér` LIKE ?)';
    $searchLike = '%' . $filterSearch . '%';
    $filmParams[] = $searchLike;
    $filmParams[] = $searchLike;
    $filmTypes .= 'ss';
}

// Sorting
if ($filterSort === 'year') {
    $filmQuery .= ' ORDER BY `rok_vydání` DESC, `název` ASC';
} elseif ($filterSort === 'year_asc') {
    $filmQuery .= ' ORDER BY `rok_vydání` ASC, `název` ASC';
} else {
    $filmQuery .= ' ORDER BY `název` ASC';
}

$stmt = $conn->prepare($filmQuery);
if (!empty($filmParams)) {
    $stmt->bind_param($filmTypes, ...$filmParams);
}
$stmt->execute();
$films = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch total film count (unfiltered)
$totalFilmsResult = $conn->query("SELECT COUNT(*) as total FROM `film`");
$totalFilms = $totalFilmsResult ? (int)$totalFilmsResult->fetch_assoc()['total'] : 0;

// Fetch top rated films
$topFilmsResult = $conn->query(
    "SELECT f.`ID_film`, f.`název`, f.`režisér`, AVG(r.`score`) as avg_score, COUNT(r.`id`) as rating_count
     FROM `film` f
     INNER JOIN `ratings` r ON f.`ID_film` = r.`film_id`
     GROUP BY f.`ID_film`
     HAVING rating_count >= 1
     ORDER BY avg_score DESC, rating_count DESC
     LIMIT 5"
);
$topFilms = $topFilmsResult ? $topFilmsResult->fetch_all(MYSQLI_ASSOC) : [];

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
    <link rel="icon" type="image/x-icon" href="/favicon.png">
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
            <p class="mt-2 text-xl font-semibold text-white"><?= $totalFilms ?> in catalog</p>
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

    <?php if (!empty($topFilms)): ?>
    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-lg font-semibold text-white">Rated Films</h2>
                <p class="text-sm text-slate-400">Community favorites based on member ratings.</p>
            </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <?php foreach ($topFilms as $index => $topFilm): ?>
            <a href="ratings.php?film_id=<?= (int)$topFilm['ID_film'] ?>" class="rounded-xl bg-slate-900/60 p-4 ring-1 ring-white/10 hover:bg-slate-800/60 transition">
                <div class="flex items-start justify-between">
                    <span class="rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-semibold text-emerald-300">#<?= $index + 1 ?></span>
                    <span class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-xs font-semibold text-indigo-300"><?= number_format((float)$topFilm['avg_score'], 1) ?>/5</span>
                </div>
                <p class="mt-2 font-medium text-white truncate"><?= htmlspecialchars($topFilm['název']) ?></p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($topFilm['režisér']) ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= (int)$topFilm['rating_count'] ?> ratings</p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-lg font-semibold text-white">Catalog</h2>
                <p class="text-sm text-slate-400">Browse every film the club is tracking.</p>
            </div>
        </div>
        <form method="get" class="mt-4 flex flex-wrap items-center gap-3">
            <input name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Search by title or director..."
                   class="flex-1 min-w-48 rounded-lg bg-slate-900/60 px-3 py-2 text-sm text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
            <select name="genre" class="rounded-lg bg-slate-900/60 px-3 py-2 text-sm text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none">
                <option value="">All genres</option>
                <?php foreach ($genres as $g): ?>
                    <option value="<?= htmlspecialchars($g['žánr']) ?>" <?= $filterGenre === $g['žánr'] ? 'selected' : '' ?>><?= htmlspecialchars($g['žánr']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="rounded-lg bg-slate-900/60 px-3 py-2 text-sm text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none">
                <option value="name" <?= $filterSort === 'name' ? 'selected' : '' ?>>Sort by name</option>
                <option value="year" <?= $filterSort === 'year' ? 'selected' : '' ?>>Newest first</option>
                <option value="year_asc" <?= $filterSort === 'year_asc' ? 'selected' : '' ?>>Oldest first</option>
            </select>
            <button type="submit" class="rounded-lg bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-500/30">Filter</button>
            <?php if ($filterGenre !== '' || $filterSearch !== '' || $filterSort !== 'name'): ?>
                <a href="dashboard.php" class="rounded-lg px-3 py-2 text-sm text-slate-400 hover:text-white">Reset</a>
            <?php endif; ?>
        </form>
        <?php if ($filterGenre !== '' || $filterSearch !== ''): ?>
            <p class="mt-2 text-sm text-slate-400">Showing <?= count($films) ?> of <?= $totalFilms ?> films</p>
        <?php endif; ?>
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
                        <tr class="hover:bg-white/5 transition" onclick="window.location.href='ratings.php?film_id=<?= (int)$film['ID_film'] ?>'">                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($film['název']) ?></td>
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
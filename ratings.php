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
$selectedFilmId = isset($_GET['film_id']) ? (int)$_GET['film_id'] : 0;

// Ensure ratings table exists
$conn->query(
'CREATE TABLE IF NOT EXISTS `ratings` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
`film_id` INT NOT NULL,
`member_id` INT NOT NULL,
`score` TINYINT NOT NULL,
`comment` TEXT,
`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
UNIQUE KEY `member_film` (`film_id`, `member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$alertMessage = '';

// Fetch films for dropdown and validation
$filmsResult = $conn->query('SELECT `ID_film`, `název`, `režisér`, `rok_vydání` FROM `film` ORDER BY `název`');
$films = $filmsResult ? $filmsResult->fetch_all(MYSQLI_ASSOC) : [];
$filmLookup = [];
foreach ($films as $filmRow) {
$filmLookup[(int)$filmRow['ID_film']] = $filmRow;
}

if ($selectedFilmId && !isset($filmLookup[$selectedFilmId])) {
$selectedFilmId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_rating') {
$filmId = (int)($_POST['film_id'] ?? 0);
$score = (int)($_POST['score'] ?? 0);
$comment = trim($_POST['comment'] ?? '');
$selectedFilmId = $filmId;

if (!isset($filmLookup[$selectedFilmId])) {
$selectedFilmId = 0;
}

if ($filmId === 0 || !isset($filmLookup[$filmId])) {
$alertMessage = 'Please choose a film from the catalog to rate.';
} elseif ($score < 1 || $score > 5) {
$alertMessage = 'Ratings must be between 1 and 5 stars.';
} else {
$stmt = $conn->prepare(
'INSERT INTO `ratings` (`film_id`, `member_id`, `score`, `comment`) VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE `score` = VALUES(`score`), `comment` = VALUES(`comment`), `updated_at` = CURRENT_TIMESTAMP'
);
$stmt->bind_param('iiis', $filmId, $memberId, $score, $comment);

if ($stmt->execute()) {
$alertMessage = 'Your rating has been saved. Thanks for sharing!';
$selectedFilmId = $filmId;
} else {
$alertMessage = 'We could not save your rating right now. Please try again later.';
}

$stmt->close();
}
}

// Fetch recent ratings with member names
$ratingsStmt = $conn->prepare(
'SELECT r.`id`, r.`film_id`, r.`score`, r.`comment`, r.`created_at`, r.`updated_at`,
f.`název` AS film_title, c.`jméno`, c.`příjmení`
FROM `ratings` r
LEFT JOIN `film` f ON r.`film_id` = f.`ID_film`
LEFT JOIN `člen` c ON r.`member_id` = c.`ID_člen`
ORDER BY r.`updated_at` DESC'
);
$ratingsStmt->execute();
$ratingsResult = $ratingsStmt->get_result();
$ratings = $ratingsResult ? $ratingsResult->fetch_all(MYSQLI_ASSOC) : [];
$ratingsStmt->close();

// Average ratings per film
$averageResult = $conn->query(
'SELECT r.`film_id`, f.`název` AS film_title, AVG(r.`score`) AS avg_score, COUNT(*) AS total_ratings
FROM `ratings` r
LEFT JOIN `film` f ON r.`film_id` = f.`ID_film`
GROUP BY r.`film_id`
ORDER BY f.`název`'
);
$averages = $averageResult ? $averageResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!doctype html>
<html lang="en" class="bg-slate-950 text-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filmio | Ratings</title>
    <link rel="icon" type="image/x-icon" href="/favicon.png">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-slate-950">
<header class="sticky top-0 z-10 border-b border-white/5 bg-slate-950/80 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <div>
            <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Film Club</p>
            <h1 class="text-xl font-semibold text-white">Rate a Film</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <p class="text-sm font-medium text-white"><?= htmlspecialchars($memberName) ?></p>
            </div>
            <a href="dashboard.php" class="rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/15 hover:bg-white/20">Back to dashboard</a>
        </div>
    </div>
</header>

<main class="mx-auto flex max-w-6xl flex-col gap-8 px-6 py-10">
    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h2 class="text-lg font-semibold text-white">Share your rating</h2>
                <p class="text-sm text-slate-400">Select a film from the catalog, rate it, and add an optional note.</p>
            </div>
        </div>
        <?php if ($alertMessage): ?>
            <div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100 ring-1 ring-emerald-500/20">
                <?= htmlspecialchars($alertMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-6 grid gap-4 md:grid-cols-2">
            <div class="space-y-2">
                <label class="text-sm text-slate-300">Film</label>
                <select name="film_id" required class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none">
                    <option value="" disabled <?= $selectedFilmId === 0 ? 'selected' : '' ?>>Select a film</option>
                    <?php foreach ($films as $film): ?>
                        <option value="<?= (int)$film['ID_film'] ?>" <?= $selectedFilmId === (int)$film['ID_film'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($film['název']) ?> (<?= (int)$film['rok_vydání'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-sm text-slate-300">Rating</label>
                <div class="flex items-center gap-3">
                    <input type="number" name="score" min="1" max="5" required class="w-24 rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" placeholder="1-5">
                    <p class="text-sm text-slate-400">1 = poor, 5 = outstanding</p>
                </div>
            </div>
            <div class="md:col-span-2 space-y-2">
                <label class="text-sm text-slate-300">Comment (optional)</label>
                <textarea name="comment" rows="3" class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" placeholder="What stood out to you?"></textarea>
            </div>
            <div class="md:col-span-2 flex justify-end">
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                    Submit rating
                </button>
                <input type="hidden" name="action" value="submit_rating">
            </div>
        </form>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Average ratings</h2>
                    <p class="text-sm text-slate-400">See how the community feels about each film.</p>
                </div>
            </div>
            <div class="mt-4 divide-y divide-white/5">
                <?php if ($averages): ?>
                    <?php foreach ($averages as $avg): ?>
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="font-medium text-white"><?= htmlspecialchars($avg['film_title']) ?></p>
                                <p class="text-xs text-slate-400">Based on <?= (int)$avg['total_ratings'] ?> ratings</p>
                            </div>
                            <div class="rounded-full bg-emerald-500/15 px-3 py-1 text-sm font-semibold text-emerald-100 ring-1 ring-emerald-500/20">
                                <?= number_format((float)$avg['avg_score'], 1) ?> / 5
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="py-4 text-sm text-slate-400">No ratings yet. Be the first to add one!</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Recent ratings</h2>
                    <p class="text-sm text-slate-400">What members are saying right now.</p>
                </div>
            </div>
            <div class="mt-4 space-y-4">
                <?php if ($ratings): ?>
                    <?php foreach ($ratings as $rating): ?>
                        <article class="rounded-xl bg-slate-900/60 p-4 ring-1 ring-white/10">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-semibold text-white"><?= htmlspecialchars($rating['film_title'] ?? 'Unknown film') ?></p>
                                    <p class="text-xs text-slate-400">By <?= htmlspecialchars(trim(($rating['jméno'] ?? '') . ' ' . ($rating['příjmení'] ?? ''))) ?: 'Member' ?></p>
                                </div>
                                <div class="rounded-full bg-indigo-500/15 px-3 py-1 text-sm font-semibold text-indigo-100 ring-1 ring-indigo-500/20">
                                    <?= (int)$rating['score'] ?> / 5
                                </div>
                            </div>
                            <?php if (!empty($rating['comment'])): ?>
                                <p class="mt-3 text-sm text-slate-200 leading-relaxed">"<?= nl2br(htmlspecialchars($rating['comment'])) ?>"</p>
                            <?php endif; ?>
                            <p class="mt-2 text-xs text-slate-500">Updated <?= htmlspecialchars(date('M j, Y', strtotime($rating['updated_at']))) ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-sm text-slate-400">No member ratings yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
</body>
</html>
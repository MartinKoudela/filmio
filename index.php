<?php
$dbPath = __DIR__ . '/data';
if (!is_dir($dbPath)) {
    mkdir($dbPath, 0755, true);
}
$dbFile = $dbPath . '/films.db';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS films (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        director TEXT NOT NULL,
        year INTEGER NOT NULL,
        genre TEXT NOT NULL,
        synopsis TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
} catch (PDOException $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    exit;
}

// Handle submissions
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $filmId = (int)($_POST['film_id'] ?? 0);
        if ($filmId) {
            $stmt = $pdo->prepare('DELETE FROM films WHERE id = :id');
            $stmt->execute([':id' => $filmId]);
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $director = trim($_POST['director'] ?? '');
        $year = (int)($_POST['year'] ?? 0);
        $genre = trim($_POST['genre'] ?? '');
        $synopsis = trim($_POST['synopsis'] ?? '');

        if ($title === '' || $director === '' || $year <= 0 || $genre === '' || $synopsis === '') {
            $errors[] = 'Please complete all fields.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO films (title, director, year, genre, synopsis) VALUES (:title, :director, :year, :genre, :synopsis)');
            $stmt->execute([
                    ':title' => $title,
                    ':director' => $director,
                    ':year' => $year,
                    ':genre' => $genre,
                    ':synopsis' => $synopsis,
            ]);
        }
    }
}

$filter = trim($_GET['q'] ?? '');
$query = 'SELECT * FROM films';
$params = [];
if ($filter !== '') {
    $query .= ' WHERE title LIKE :filter';
    $params[':filter'] = '%' . $filter . '%';
}
$query .= ' ORDER BY created_at DESC';
$films = $pdo->prepare($query);
$films->execute($params);
$filmRows = $films->fetchAll(PDO::FETCH_ASSOC);
$filmCount = count($filmRows);
?>
<!doctype html>
<html lang="en" class="bg-slate-950 text-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Filmio | Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

</head>
<body class="min-h-screen bg-slate-950 flex items-start justify-center py-12 px-6">
<section class="w-full max-w-6xl space-y-8">
    <header class="flex items-center justify-between">
        <div class="space-y-2">
            <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Film Club</p>
            <h1 class="text-3xl font-semibold text-white">Admin Panel</h1>
            <p class="text-slate-400">Manage screenings, keep the catalog tidy, and share what is playing next.</p>
        </div>
        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 px-4 py-2 text-sm font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30">
            <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
            Live workspace
        </span>
    </header>

    <?php if ($errors): ?>
        <div class="rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-100">
            <?= htmlspecialchars(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3">
        <form method="post" class="col-span-2 space-y-4 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div>
                <h2 class="text-xl font-semibold text-white">Add a film</h2>
                <p class="text-sm text-slate-400">Create a new screening entry by filling in the details below.</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Title</span>
                    <input name="title" required placeholder="The Grand Budapest Hotel"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Director</span>
                    <input name="director" required placeholder="Wes Anderson"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Year</span>
                    <input name="year" type="number" min="1888" max="2100" required placeholder="2014"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Genre</span>
                    <input name="genre" required placeholder="Comedy / Drama"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                    <span class="block">Synopsis</span>
                    <textarea name="synopsis" rows="3" required placeholder="A concierge and his lobby boy get tangled in a caper across a pastel Europe." class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none"></textarea>
                </label>
            </div>
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                <span>Save entry</span>
            </button>
            <input type="hidden" name="action" value="create">
        </form>

        <aside class="space-y-3 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Club health</h2>
                <span class="rounded-full bg-white/10 px-3 py-1 text-sm text-white"><?= $filmCount ?> <?= $filmCount === 1 ? 'film' : 'films' ?></span>
            </div>
            <p class="text-sm text-slate-400">Track catalog size, export sessions, or share with members. Add or remove films as the lineup evolves.</p>
            <ul class="divide-y divide-white/5 text-sm text-slate-300">
                <li class="flex items-center justify-between py-3">
                    <span>Upcoming screenings</span>
                    <span class="rounded-lg bg-slate-900/60 px-3 py-1 text-white">Weekly</span>
                </li>
                <li class="flex items-center justify-between py-3">
                    <span>Moderation</span>
                    <span class="rounded-lg bg-slate-900/60 px-3 py-1 text-white">Enabled</span>
                </li>
                <li class="flex items-center justify-between py-3">
                    <span>Export</span>
                    <button class="rounded-lg bg-slate-900/60 px-3 py-1 text-white ring-1 ring-white/10">CSV</button>
                </li>
            </ul>
        </aside>
    </div>

    <section class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-white">Catalog</h2>
                <p class="text-sm text-slate-400">Edit or remove films directly below.</p>
            </div>
            <form class="flex items-center gap-2" method="get">
                <input name="q" value="<?= htmlspecialchars($filter) ?>" placeholder="Filter by title"
                       class="w-52 rounded-lg bg-slate-900/60 px-3 py-2 text-sm text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                <button type="submit" class="rounded-lg px-3 py-2 text-sm text-white ring-1 ring-white/10 hover:bg-white/5">Apply</button>
                <a href="?" class="rounded-lg px-3 py-2 text-sm text-white ring-1 ring-white/10 hover:bg-white/5">Reset</a>
            </form>
        </div>
        <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="min-w-full text-left text-sm text-slate-200">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Director</th>
                    <th class="px-4 py-3">Year</th>
                    <th class="px-4 py-3">Genre</th>
                    <th class="px-4 py-3">Synopsis</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($filmRows): ?>
                    <?php foreach ($filmRows as $film): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($film['title']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($film['director']) ?></td>
                            <td class="px-4 py-3"><?= (int)$film['year'] ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($film['genre']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($film['synopsis']) ?></td>
                            <td class="px-4 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="film_id" value="<?= (int)$film['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="rounded-lg bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-200 ring-1 ring-red-500/40 hover:bg-red-500/30" onclick="return confirm('Delete this film?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-slate-400">No films found. Add the first title above.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
</body>
</html>
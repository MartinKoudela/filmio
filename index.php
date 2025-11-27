<?php
// Lightweight .env support so local credentials can be placed in a file
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        if ($key !== '' && getenv($key) === false) {
            putenv(sprintf('%s=%s', $key, trim($value)));
        }
    }
}

// Database setup (expects existing tables in the configured database)
$dbDsn = getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/data/films.db';
$dbUser = getenv('DB_USER') ?: null;
$dbPass = getenv('DB_PASSWORD') ?: null;

// Ensure SQLite directory exists when using the default DSN
if (str_starts_with($dbDsn, 'sqlite:')) {
    $dbFile = substr($dbDsn, strlen('sqlite:'));
    $dbDir = dirname($dbFile);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
}

$pdo = null;
$connectionError = null;

try {
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $connectionError = $e->getMessage();
}

// Handle submissions
$errors = [];
$notices = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $target = $_POST['target'] ?? 'films';
    $action = $_POST['action'] ?? 'create';

    if ($target === 'films') {
        if ($action === 'delete') {
            $filmId = (int)($_POST['film_id'] ?? 0);
            if ($filmId) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM films WHERE id = :id');
                    $stmt->execute([':id' => $filmId]);
                    $notices[] = 'Film deleted.';
                } catch (PDOException $e) {
                    $errors[] = 'Film deletion failed: ' . $e->getMessage();
                }
            }
        } else {
            $title = trim($_POST['title'] ?? '');
            $director = trim($_POST['director'] ?? '');
            $year = (int)($_POST['year'] ?? 0);
            $genre = trim($_POST['genre'] ?? '');
            $synopsis = trim($_POST['synopsis'] ?? '');

            if ($title === '' || $director === '' || $year <= 0 || $genre === '' || $synopsis === '') {
                $errors[] = 'Please complete all film fields.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO films (title, director, year, genre, synopsis) VALUES (:title, :director, :year, :genre, :synopsis)');
                    $stmt->execute([
                            ':title' => $title,
                            ':director' => $director,
                            ':year' => $year,
                            ':genre' => $genre,
                            ':synopsis' => $synopsis,
                    ]);
                    $notices[] = 'Film saved.';
                } catch (PDOException $e) {
                    $errors[] = 'Film save failed: ' . $e->getMessage();
                }
            }
        }
    }

    if ($target === 'members') {
        if ($action === 'delete') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM members WHERE id = :id');
                    $stmt->execute([':id' => $memberId]);
                    $notices[] = 'Member removed.';
                } catch (PDOException $e) {
                    $errors[] = 'Member deletion failed: ' . $e->getMessage();
                }
            }
        } else {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? '');
            if ($name === '' || $email === '' || $role === '') {
                $errors[] = 'Please complete all member fields.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO members (name, email, role) VALUES (:name, :email, :role)');
                    $stmt->execute([
                            ':name' => $name,
                            ':email' => $email,
                            ':role' => $role,
                    ]);
                    $notices[] = 'Member saved.';
                } catch (PDOException $e) {
                    $errors[] = 'Member save failed: ' . $e->getMessage();
                }
            }
        }
    }

    if ($target === 'screenings') {
        if ($action === 'delete') {
            $screeningId = (int)($_POST['screening_id'] ?? 0);
            if ($screeningId) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM screenings WHERE id = :id');
                    $stmt->execute([':id' => $screeningId]);
                    $notices[] = 'Screening deleted.';
                } catch (PDOException $e) {
                    $errors[] = 'Screening deletion failed: ' . $e->getMessage();
                }
            }
        } else {
            $filmTitle = trim($_POST['film_title'] ?? '');
            $host = trim($_POST['host'] ?? '');
            $screenDate = trim($_POST['screen_date'] ?? '');
            $venue = trim($_POST['venue'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($filmTitle === '' || $host === '' || $screenDate === '' || $venue === '' || $notes === '') {
                $errors[] = 'Please complete all screening fields.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO screenings (film_title, host, screen_date, venue, notes) VALUES (:film_title, :host, :screen_date, :venue, :notes)');
                    $stmt->execute([
                            ':film_title' => $filmTitle,
                            ':host' => $host,
                            ':screen_date' => $screenDate,
                            ':venue' => $venue,
                            ':notes' => $notes,
                    ]);
                    $notices[] = 'Screening saved.';
                } catch (PDOException $e) {
                    $errors[] = 'Screening save failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$filter = trim($_GET['q'] ?? '');
$filmRows = $memberRows = $screeningRows = [];
$filmCount = 0;

if ($pdo) {
    $query = 'SELECT * FROM films';
    $params = [];
    if ($filter !== '') {
        $query .= ' WHERE title LIKE :filter';
        $params[':filter'] = '%' . $filter . '%';
    }
    $query .= ' ORDER BY created_at DESC';

    try {
        $films = $pdo->prepare($query);
        $films->execute($params);
        $filmRows = $films->fetchAll();
        $filmCount = count($filmRows);
    } catch (PDOException $e) {
        $errors[] = 'Film fetch failed: ' . $e->getMessage();
    }

    try {
        $memberRows = $pdo->query('SELECT * FROM members ORDER BY joined_at DESC')->fetchAll();
    } catch (PDOException $e) {
        $errors[] = 'Member fetch failed: ' . $e->getMessage();
    }

    try {
        $screeningRows = $pdo->query('SELECT * FROM screenings ORDER BY created_at DESC')->fetchAll();
    } catch (PDOException $e) {
        $errors[] = 'Screening fetch failed: ' . $e->getMessage();
    }
}
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
    <header class="flex flex-col gap-6 rounded-3xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
        <div class="flex items-center justify-between gap-6">
            <div class="space-y-2">
                <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Film Club</p>
                <h1 class="text-3xl font-semibold text-white">Admin Panel</h1>
                <p class="text-slate-400">Manage screenings, members, and catalog in one workspace.</p>
            </div>
            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 px-4 py-2 text-sm font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30">
                <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Live workspace
            </span>
        </div>
        <nav class="flex flex-wrap items-center gap-3 text-sm text-slate-200">
            <a href="#films" class="rounded-full bg-white/10 px-4 py-2 ring-1 ring-white/15 hover:bg-white/20">Films</a>
            <a href="#members" class="rounded-full bg-white/5 px-4 py-2 ring-1 ring-white/10 hover:bg-white/15">Members</a>
            <a href="#screenings" class="rounded-full bg-white/5 px-4 py-2 ring-1 ring-white/10 hover:bg-white/15">Screenings</a>
        </nav>
    </header>

    <?php if ($errors): ?>
        <div class="rounded-xl bg-red-500/10 border border-red-500/30 p-4 text-sm text-red-100">
            <?= htmlspecialchars(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3" id="films">
        <form method="post" class="col-span-2 space-y-4 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-white">Add a film</h2>
                    <p class="text-sm text-slate-400">Create a new screening entry by filling in the details below.</p>
                </div>
                <span class="rounded-lg bg-white/10 px-3 py-1 text-xs text-slate-200 ring-1 ring-white/15">Catalog: <?= $filmCount ?> <?= $filmCount === 1 ? 'film' : 'films' ?></span>
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
            <input type="hidden" name="target" value="films">
            <input type="hidden" name="action" value="create">
        </form>

        <aside class="space-y-3 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Club health</h2>
                <span class="rounded-full bg-white/10 px-3 py-1 text-sm text-white">3 panels</span>
            </div>
            <p class="text-sm text-slate-400">Track catalog size, member roster, and upcoming screenings at a glance.</p>
            <ul class="divide-y divide-white/5 text-sm text-slate-300">
                <li class="flex items-center justify-between py-3">
                    <span>Members</span>
                    <span class="rounded-lg bg-slate-900/60 px-3 py-1 text-white"><?= count($memberRows) ?></span>
                </li>
                <li class="flex items-center justify-between py-3">
                    <span>Screenings</span>
                    <span class="rounded-lg bg-slate-900/60 px-3 py-1 text-white"><?= count($screeningRows) ?></span>
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
            <form class="flex flex-wrap items-center gap-2" method="get">
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
                                    <input type="hidden" name="target" value="films">
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

    <section id="members" class="grid gap-6 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur md:grid-cols-2">
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Member roster</h2>
                    <p class="text-sm text-slate-400">Add coordinators or new members with their roles.</p>
                </div>
                <span class="rounded-lg bg-white/10 px-3 py-1 text-xs text-slate-200 ring-1 ring-white/15"><?= count($memberRows) ?> total</span>
            </div>
            <form method="post" class="space-y-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Name</span>
                        <input name="name" required placeholder="Jordan Sanders" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Email</span>
                        <input name="email" type="email" required placeholder="jordan@club.com" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                        <span class="block">Role</span>
                        <input name="role" required placeholder="Programmer / Host" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                </div>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/70">Save member</button>
                <input type="hidden" name="target" value="members">
                <input type="hidden" name="action" value="create">
            </form>
        </div>
        <div class="overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="min-w-full divide-y divide-white/5 text-left text-sm text-slate-200">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($memberRows): ?>
                    <?php foreach ($memberRows as $member): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($member['name']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($member['email']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($member['role']) ?></td>
                            <td class="px-4 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                                    <input type="hidden" name="target" value="members">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="rounded-lg bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-200 ring-1 ring-red-500/40 hover:bg-red-500/30" onclick="return confirm('Remove this member?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-slate-400">No members yet. Add your first collaborator.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="screenings" class="grid gap-6 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur md:grid-cols-2">
        <div class="space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Screening schedule</h2>
                    <p class="text-sm text-slate-400">Lock in dates, hosts, and venues for your lineup.</p>
                </div>
                <span class="rounded-lg bg-white/10 px-3 py-1 text-xs text-slate-200 ring-1 ring-white/15"><?= count($screeningRows) ?> scheduled</span>
            </div>
            <form method="post" class="space-y-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Film title</span>
                        <input name="film_title" required placeholder="Portrait of a Lady on Fire" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Host</span>
                        <input name="host" required placeholder="Marisol" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Date</span>
                        <input name="screen_date" type="date" required class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Venue</span>
                        <input name="venue" required placeholder="Main Hall" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                        <span class="block">Notes</span>
                        <textarea name="notes" rows="3" required placeholder="Snacks at 7pm, film at 7:30." class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none"></textarea>
                    </label>
                </div>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-indigo-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-200">Save screening</button>
                <input type="hidden" name="target" value="screenings">
                <input type="hidden" name="action" value="create">
            </form>
        </div>
        <div class="overflow-hidden rounded-xl ring-1 ring-white/10">
            <table class="min-w-full divide-y divide-white/5 text-left text-sm text-slate-200">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
                <tr>
                    <th class="px-4 py-3">Film</th>
                    <th class="px-4 py-3">Host</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Venue</th>
                    <th class="px-4 py-3">Notes</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($screeningRows): ?>
                    <?php foreach ($screeningRows as $screening): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($screening['film_title']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['host']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($screening['screen_date']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['venue']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($screening['notes']) ?></td>
                            <td class="px-4 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="screening_id" value="<?= (int)$screening['id'] ?>">
                                    <input type="hidden" name="target" value="screenings">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="rounded-lg bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-200 ring-1 ring-red-500/40 hover:bg-red-500/30" onclick="return confirm('Delete this screening?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-slate-400">No screenings scheduled. Add one on the left.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
</body>
</html>
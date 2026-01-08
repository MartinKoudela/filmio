<?php

session_start();
require_once __DIR__ . '/db_connect.php';

// Check session timeout
checkSessionTimeout();

if (empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}


if ($conn->connect_errno) {
    die('DB connection failed: ' . $conn->connect_error);
}

$filter = isset($_GET['q']) ? trim($_GET['q']) : '';
$editFilmId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editFilm = null;

if ($editFilmId > 0) {
    $stmt = $conn->prepare("SELECT * FROM `film` WHERE `ID_film` = ? LIMIT 1");
    $stmt->bind_param('i', $editFilmId);
    $stmt->execute();
    $editFilm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Flash message helper
function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// ---------- ZPRACOVÁNÍ POST (CREATE / DELETE) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = $_POST['target'] ?? '';
    $action = $_POST['action'] ?? '';

    // FILMY ------------------------------------------------
    if ($target === 'films') {
        if ($action === 'create') {
            $title    = trim($_POST['title'] ?? '');
            $director = trim($_POST['director'] ?? '');
            $year     = (int)($_POST['year'] ?? 0);
            $genre    = trim($_POST['genre'] ?? '');
            $synopsis = trim($_POST['synopsis'] ?? '');
            $length   = 0; // délka – nemáš ve formuláři, tak default 0

            if ($title !== '' && $director !== '' && $year > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO `film` (`název`, `rok_vydání`, `režisér`, `popis`, `délka`, `žánr`)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('sissis', $title, $year, $director, $synopsis, $length, $genre);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Film "' . $title . '" was added successfully.');
            }
        }

        if ($action === 'delete') {
            $filmId = (int)($_POST['film_id'] ?? 0);
            if ($filmId > 0) {
                $stmt = $conn->prepare("DELETE FROM `film` WHERE `ID_film` = ?");
                $stmt->bind_param('i', $filmId);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Film was deleted successfully.');
            }
        }

        if ($action === 'update') {
            $filmId   = (int)($_POST['film_id'] ?? 0);
            $title    = trim($_POST['title'] ?? '');
            $director = trim($_POST['director'] ?? '');
            $year     = (int)($_POST['year'] ?? 0);
            $genre    = trim($_POST['genre'] ?? '');
            $synopsis = trim($_POST['synopsis'] ?? '');

            if ($filmId > 0 && $title !== '' && $director !== '' && $year > 0) {
                $stmt = $conn->prepare("
                    UPDATE `film`
                    SET `název` = ?, `rok_vydání` = ?, `režisér` = ?, `popis` = ?, `žánr` = ?
                    WHERE `ID_film` = ?
                ");
                $stmt->bind_param('sisssi', $title, $year, $director, $synopsis, $genre, $filmId);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Film "' . $title . '" was updated successfully.');
            }
        }
    }

    // ČLENOVÉ ----------------------------------------------
    if ($target === 'members') {
        if ($action === 'create') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role  = trim($_POST['role'] ?? '');

            // vše uložím do tabulky `člen`
            $firstName  = $name;    // celé jméno do jméno
            $lastName   = '';       // příjmení necháme prázdné
            $address    = '';       // adresa prázdná
            $membership = $role;    // role -> členství
            $regDate    = date('Y-m-d');

            if ($name !== '' && $email !== '' && $role !== '') {
                $stmt = $conn->prepare("
                    INSERT INTO `člen`
                        (`jméno`, `příjmení`, `email`, `adresa`, `datum_registrace`, `členství`)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'ssssss',
                    $firstName,
                    $lastName,
                    $email,
                    $address,
                    $regDate,
                    $membership
                );
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Member "' . $name . '" was added successfully.');
            }
        }

        if ($action === 'delete') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId > 0) {
                $stmt = $conn->prepare("DELETE FROM `člen` WHERE `ID_člen` = ?");
                $stmt->bind_param('i', $memberId);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Member was removed successfully.');
            }
        }
    }

    // CSV EXPORT -------------------------------------------
    if ($target === 'export') {
        $exportType = $_POST['export_type'] ?? 'films';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="filmio_' . $exportType . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        if ($exportType === 'films') {
            fputcsv($output, ['ID', 'Title', 'Director', 'Year', 'Genre', 'Synopsis']);
            $result = $conn->query("SELECT `ID_film`, `název`, `režisér`, `rok_vydání`, `žánr`, `popis` FROM `film` ORDER BY `název`");
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [$row['ID_film'], $row['název'], $row['režisér'], $row['rok_vydání'], $row['žánr'], $row['popis']]);
            }
        } elseif ($exportType === 'members') {
            fputcsv($output, ['ID', 'Name', 'Email', 'Role', 'Registered']);
            $result = $conn->query("SELECT `ID_člen`, `jméno`, `příjmení`, `email`, `členství`, `datum_registrace` FROM `člen` ORDER BY `datum_registrace` DESC");
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [$row['ID_člen'], trim($row['jméno'] . ' ' . $row['příjmení']), $row['email'], $row['členství'], $row['datum_registrace']]);
            }
        } elseif ($exportType === 'screenings') {
            fputcsv($output, ['ID', 'Film', 'Date', 'Time', 'Venue']);
            $result = $conn->query("SELECT s.`ID_promítání`, f.`název`, s.`datum`, s.`čas`, s.`místo` FROM `sraz` s LEFT JOIN `film` f ON s.`ID_film` = f.`ID_film` ORDER BY s.`datum` DESC");
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [$row['ID_promítání'], $row['název'] ?? 'Unknown', $row['datum'], $row['čas'], $row['místo']]);
            }
        }

        fclose($output);
        exit;
    }

    // SRAZY / PROMÍTÁNÍ ------------------------------------
    if ($target === 'screenings') {
        if ($action === 'create') {
            $filmId     = (int)($_POST['film_id'] ?? 0);
            $screenDate = $_POST['screen_date'] ?? '';
            $screenTime = $_POST['screen_time'] ?? '19:00';
            $venue      = trim($_POST['venue'] ?? '');

            if ($filmId > 0 && $screenDate !== '' && $venue !== '') {
                $stmt = $conn->prepare("
                    INSERT INTO `sraz` (`datum`, `čas`, `místo`, `ID_film`)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param('sssi', $screenDate, $screenTime, $venue, $filmId);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Screening was scheduled successfully.');
            }
        }

        if ($action === 'delete') {
            $screeningId = (int)($_POST['screening_id'] ?? 0);
            if ($screeningId > 0) {
                $stmt = $conn->prepare("DELETE FROM `sraz` WHERE `ID_promítání` = ?");
                $stmt->bind_param('i', $screeningId);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('Screening was deleted successfully.');
            }
        }
    }

    // redirect po POST abys neměl resubmit formuláře
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $redirectUrl);
    exit;
}

// ---------- NAČTENÍ DAT PRO ŠABLONU ----------

// FILMY
if ($filter !== '') {
    $like = '%' . $filter . '%';
    $stmt = $conn->prepare("SELECT * FROM `film` WHERE `název` LIKE ? ORDER BY `název`");
    $stmt->bind_param('s', $like);
} else {
    $stmt = $conn->prepare("SELECT * FROM `film` ORDER BY `název`");
}
$stmt->execute();
$filmRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->query("SELECT COUNT(*) AS c FROM `film`");
$filmCountRow = $stmt->fetch_assoc();
$filmCount = (int)$filmCountRow['c'];
$stmt->close();

// ČLENOVÉ
$stmt = $conn->prepare("SELECT * FROM `člen` ORDER BY `datum_registrace` DESC");
$stmt->execute();
$memberRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// SRAZY / PROMÍTÁNÍ (+ název filmu do extra pole)
$stmt = $conn->prepare("
    SELECT s.*, f.`název` AS film_název
    FROM `sraz` s
    LEFT JOIN `film` f ON s.`ID_film` = f.`ID_film`
    ORDER BY s.`datum` DESC
");
$stmt->execute();
$screeningRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en" class="bg-slate-950 text-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Filmio | Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.png">
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

    <?php if ($flashMessage): ?>
        <div class="rounded-xl border <?= $flashType === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' : 'border-red-500/30 bg-red-500/10 text-red-100' ?> px-4 py-3 text-sm ring-1 <?= $flashType === 'success' ? 'ring-emerald-500/20' : 'ring-red-500/20' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3" id="films">
        <form method="post" class="col-span-2 space-y-4 rounded-2xl bg-white/5 p-6 ring-1 ring-white/10 backdrop-blur <?= $editFilm ? 'ring-2 ring-indigo-500/50' : '' ?>">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-white"><?= $editFilm ? 'Edit film' : 'Add a film' ?></h2>
                    <p class="text-sm text-slate-400"><?= $editFilm ? 'Update the film details below.' : 'Create a new screening entry by filling in the details below.' ?></p>
                </div>
                <span class="rounded-lg bg-white/10 px-3 py-1 text-xs text-slate-200 ring-1 ring-white/15">
                    <?= $filmCount ?> <?= $filmCount === 1 ? 'film' : 'films' ?>
                </span>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Title</span>
                    <input name="title" required placeholder="The Grand Budapest Hotel" value="<?= htmlspecialchars($editFilm['název'] ?? '') ?>"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Director</span>
                    <input name="director" required placeholder="Wes Anderson" value="<?= htmlspecialchars($editFilm['režisér'] ?? '') ?>"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Year</span>
                    <input name="year" type="number" min="1888" max="2100" required placeholder="2014" value="<?= $editFilm ? (int)$editFilm['rok_vydání'] : '' ?>"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300">
                    <span class="block">Genre</span>
                    <input name="genre" required placeholder="Comedy / Drama" value="<?= htmlspecialchars($editFilm['žánr'] ?? '') ?>"
                           class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none" />
                </label>
                <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                    <span class="block">Synopsis</span>
                    <textarea name="synopsis" rows="3" required placeholder="A concierge and his lobby boy get tangled in a caper across a pastel Europe." class="w-full rounded-xl bg-slate-900/60 px-4 py-3 text-white ring-1 ring-inset ring-white/10 focus:ring-emerald-400 outline-none"><?= htmlspecialchars($editFilm['popis'] ?? '') ?></textarea>
                </label>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl <?= $editFilm ? 'bg-indigo-500 hover:bg-indigo-400' : 'bg-emerald-500 hover:bg-emerald-400' ?> px-5 py-3 text-sm font-semibold text-slate-950 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                    <span><?= $editFilm ? 'Update film' : 'Save entry' ?></span>
                </button>
                <?php if ($editFilm): ?>
                    <a href="adminMenu.php#films" class="rounded-xl px-5 py-3 text-sm font-semibold text-slate-300 hover:text-white">Cancel</a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="target" value="films">
            <input type="hidden" name="action" value="<?= $editFilm ? 'update' : 'create' ?>">
            <?php if ($editFilm): ?>
                <input type="hidden" name="film_id" value="<?= (int)$editFilm['ID_film'] ?>">
            <?php endif; ?>
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
                    <div class="flex gap-1">
                        <form method="post" class="inline">
                            <input type="hidden" name="target" value="export">
                            <input type="hidden" name="export_type" value="films">
                            <button type="submit" class="rounded-lg bg-slate-900/60 px-2 py-1 text-xs text-white ring-1 ring-white/10 hover:bg-slate-800/60">Films</button>
                        </form>
                        <form method="post" class="inline">
                            <input type="hidden" name="target" value="export">
                            <input type="hidden" name="export_type" value="members">
                            <button type="submit" class="rounded-lg bg-slate-900/60 px-2 py-1 text-xs text-white ring-1 ring-white/10 hover:bg-slate-800/60">Members</button>
                        </form>
                        <form method="post" class="inline">
                            <input type="hidden" name="target" value="export">
                            <input type="hidden" name="export_type" value="screenings">
                            <button type="submit" class="rounded-lg bg-slate-900/60 px-2 py-1 text-xs text-white ring-1 ring-white/10 hover:bg-slate-800/60">Screenings</button>
                        </form>
                    </div>
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
                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($film['název']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($film['režisér']) ?></td>
                            <td class="px-4 py-3"><?= (int)$film['rok_vydání'] ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($film['žánr']) ?></td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($film['popis']) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="?edit=<?= (int)$film['ID_film'] ?>#films" class="rounded-lg bg-indigo-500/20 px-3 py-2 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-500/40 hover:bg-indigo-500/30">Edit</a>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="film_id" value="<?= (int)$film['ID_film'] ?>">
                                        <input type="hidden" name="target" value="films">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="rounded-lg bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-200 ring-1 ring-red-500/40 hover:bg-red-500/30" onclick="return confirm('Delete this film?')">Delete</button>
                                    </form>
                                </div>
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
        <div class="overflow-x-auto rounded-xl ring-1 ring-white/10">
            <table class="min-w-full w-full divide-y divide-white/5 text-left text-sm text-slate-200">
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
                            <td class="px-4 py-3 font-medium text-white">
                                <?= htmlspecialchars($member['jméno'] . ($member['příjmení'] ? ' ' . $member['příjmení'] : '')) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-300"><?= htmlspecialchars($member['email']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($member['členství']) ?></td>
                            <td class="px-4 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="member_id" value="<?= (int)$member['ID_člen'] ?>">
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
                    <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                        <span class="block">Film</span>
                        <select name="film_id" required class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none">
                            <option value="" disabled selected>Select a film from catalog</option>
                            <?php foreach ($filmRows as $film): ?>
                                <option value="<?= (int)$film['ID_film'] ?>"><?= htmlspecialchars($film['název']) ?> (<?= (int)$film['rok_vydání'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Date</span>
                        <input name="screen_date" type="date" required class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block">Time</span>
                        <input name="screen_time" type="time" required value="19:00" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
                    </label>
                    <label class="space-y-2 text-sm text-slate-300 md:col-span-2">
                        <span class="block">Venue</span>
                        <input name="venue" required placeholder="Main Hall" class="w-full rounded-xl bg-slate-900/60 px-3 py-2 text-white ring-1 ring-white/10 focus:ring-emerald-400 outline-none" />
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
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Time</th>
                    <th class="px-4 py-3">Venue</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/5 bg-slate-950/40">
                <?php if ($screeningRows): ?>
                    <?php foreach ($screeningRows as $screening): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-medium text-white">
                                <?= htmlspecialchars($screening['film_název'] ?? 'Unknown') ?>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['datum']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['čas']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($screening['místo']) ?></td>
                            <td class="px-4 py-3">
                                <form method="post" class="inline">
                                    <input type="hidden" name="screening_id" value="<?= (int)$screening['ID_promítání'] ?>">
                                    <input type="hidden" name="target" value="screenings">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="rounded-lg bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-200 ring-1 ring-red-500/40 hover:bg-red-500/30" onclick="return confirm('Delete this screening?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-400">No screenings scheduled. Add one on the left.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
</body>
</html>

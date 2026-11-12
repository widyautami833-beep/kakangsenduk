
<?php
session_start();

require_once __DIR__ . '/../config/koneksi.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

$judul_halaman = 'Dashboard';

/* =====================================
   DATA STATISTIK
===================================== */

// Total kontestan
$totalKontestan = (int) $pdo
    ->query("SELECT COUNT(*) FROM kontestan")
    ->fetchColumn();

// Total pemilih
$totalPemilih = (int) $pdo
    ->query("SELECT COUNT(*) FROM users WHERE role = 'pemilih'")
    ->fetchColumn();

// Periode voting aktif
$stmt = $pdo->query("
    SELECT *
    FROM periode_voting
    WHERE status = 'Aktif'
    ORDER BY id DESC
    LIMIT 1
");

$periodeAktif = $stmt->fetch();

// Periode terbaru jika tidak ada yang aktif
if (!$periodeAktif) {
    $periodeAktif = $pdo->query("
        SELECT *
        FROM periode_voting
        ORDER BY id DESC
        LIMIT 1
    ")->fetch();
}

$idPeriode = $periodeAktif['id'] ?? 0;

// Total suara Kakang
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM voting
    WHERE kategori = 'Kakang' AND periode_id = ?
");
$stmt->execute([$idPeriode]);
$totalSuaraKakang = (int) $stmt->fetchColumn();

// Total suara Senduk
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM voting
    WHERE kategori = 'Senduk' AND periode_id = ?
");
$stmt->execute([$idPeriode]);
$totalSuaraSenduk = (int) $stmt->fetchColumn();

// Total seluruh suara
$totalSuara = $totalSuaraKakang + $totalSuaraSenduk;

/* =====================================
   DATA PEROLEHAN SUARA
===================================== */

function ambilPerolehanSuara($pdo, $periodeId, $kategori)
{
    $stmt = $pdo->prepare("
        SELECT
            k.id,
            k.nomor_peserta,
            k.nama,
            k.kategori,
            k.foto,
            COUNT(v.id) AS jumlah_suara
        FROM kontestan k
        LEFT JOIN voting v
            ON v.kontestan_id = k.id
            AND v.periode_id = ?
        WHERE k.kategori = ?
        GROUP BY
            k.id,
            k.nomor_peserta,
            k.nama,
            k.kategori,
            k.foto
        ORDER BY jumlah_suara DESC, k.nama ASC
    ");

    $stmt->execute([$periodeId, $kategori]);

    return $stmt->fetchAll();
}

$dataKakang = ambilPerolehanSuara($pdo, $idPeriode, 'Kakang');
$dataSenduk = ambilPerolehanSuara($pdo, $idPeriode, 'Senduk');

// Total suara berdasarkan kategori
$suaraKakang = array_sum(array_column($dataKakang, 'jumlah_suara'));
$suaraSenduk = array_sum(array_column($dataSenduk, 'jumlah_suara'));

// Kontestan teratas
$teratasKakang = $dataKakang[0] ?? null;
$teratasSenduk = $dataSenduk[0] ?? null;

// Persentase suara tertinggi
$persenKakang = ($suaraKakang > 0 && $teratasKakang)
    ? round(($teratasKakang['jumlah_suara'] / $suaraKakang) * 100)
    : 0;

$persenSenduk = ($suaraSenduk > 0 && $teratasSenduk)
    ? round(($teratasSenduk['jumlah_suara'] / $suaraSenduk) * 100)
    : 0;

// Status voting
$sekarang = date('Y-m-d H:i:s');

$votingAktif = $periodeAktif &&
    $periodeAktif['status'] === 'Aktif' &&
    $sekarang >= $periodeAktif['tanggal_mulai'] &&
    $sekarang <= $periodeAktif['tanggal_selesai'];

function tanggalDashboard($tanggal)
{
    return $tanggal
        ? date('d M Y', strtotime($tanggal))
        : '-';
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* =====================================
   DASHBOARD FULL WIDTH
===================================== */

.dashboard-page {
    width: 100%;
}

.dashboard-heading {
    margin-bottom: 22px;
}

.dashboard-heading h1 {
    font-size: 27px;
    font-weight: 800;
    color: #17385d;
    margin: 0 0 7px;
}

.dashboard-heading p {
    font-size: 12px;
    color: #8190a2;
    margin: 0;
}

/* STATUS VOTING */

.status-voting {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 15px 18px;
    border-radius: 9px;
    margin-bottom: 18px;
}

.status-voting.aktif {
    background: #e8f5ef;
    color: #287b5b;
}

.status-voting.nonaktif {
    background: #fff2e8;
    color: #a65d2d;
}

.status-voting strong {
    display: block;
    font-size: 13px;
    margin-bottom: 5px;
}

.status-voting small {
    font-size: 10px;
}

.status-voting a {
    background: #fff;
    color: #397d69;
    text-decoration: none;
    font-size: 10px;
    font-weight: 700;
    padding: 9px 15px;
    border-radius: 6px;
    white-space: nowrap;
}

.status-voting a:hover {
    background: #f2f7f5;
}

/* STATISTIK */

.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 18px;
}

.stat-card {
    background: #fff;
    border-radius: 10px;
    padding: 17px;
    min-width: 0;
}

.stat-card .stat-label {
    display: block;
    font-size: 11px;
    color: #8290a2;
    margin-bottom: 10px;
}

.stat-card h2 {
    font-size: 27px;
    font-weight: 800;
    color: #17385d;
    margin: 0 0 9px;
}

.stat-card small {
    font-size: 10px;
    color: #91a0b1;
}

/* BAGIAN GRAFIK DAN PEMENANG */

.dashboard-panels {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) minmax(260px, .9fr);
    gap: 15px;
    margin-bottom: 17px;
}

.dashboard-panel {
    background: #fff;
    border-radius: 10px;
    padding: 19px;
    min-width: 0;
}

.panel-heading {
    margin-bottom: 19px;
}

.panel-heading h3 {
    font-size: 16px;
    color: #17385d;
    font-weight: 800;
    margin: 0 0 6px;
}

.panel-heading p {
    font-size: 10px;
    color: #8997a8;
    margin: 0;
}

/* GRAFIK SUARA */

.chart-list {
    display: flex;
    flex-direction: column;
    gap: 17px;
}

.chart-item {
    display: grid;
    grid-template-columns: 85px minmax(0, 1fr) 45px;
    align-items: center;
    gap: 10px;
}

.chart-name {
    color: #71839a;
    font-size: 11px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.chart-track {
    width: 100%;
    height: 11px;
    background: #edf1f6;
    border-radius: 3px;
    overflow: hidden;
}

.chart-bar {
    height: 100%;
    border-radius: 3px;
    background: #2363b4;
}

.chart-bar.senduk {
    background: #b68d48;
}

.chart-value {
    color: #52677f;
    font-size: 10px;
    font-weight: 700;
}

/* TERATAS */

.winner-section {
    margin-bottom: 23px;
}

.winner-section:last-child {
    margin-bottom: 0;
}

.winner-category {
    display: block;
    font-size: 9px;
    color: #b08c4e;
    font-weight: 800;
    margin-bottom: 8px;
}

.winner-name {
    color: #17385d;
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 6px;
}

.winner-info {
    color: #8997a8;
    font-size: 10px;
}

.winner-empty {
    color: #8997a8;
    font-size: 11px;
}

/* INFORMASI BAWAH */

.dashboard-note {
    background: #eaf1fa;
    border-radius: 9px;
    padding: 14px 17px;
}

.dashboard-note strong {
    display: block;
    color: #29496d;
    font-size: 11px;
    margin-bottom: 6px;
}

.dashboard-note p {
    color: #8292a5;
    font-size: 10px;
    margin: 0;
}

/* RESPONSIVE */

@media(max-width: 1000px) {
    .dashboard-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-panels {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 550px) {
    .dashboard-heading h1 {
        font-size: 22px;
    }

    .dashboard-stats {
        grid-template-columns: 1fr 1fr;
        gap: 9px;
    }

    .stat-card {
        padding: 13px;
    }

    .stat-card h2 {
        font-size: 22px;
    }

    .status-voting {
        align-items: flex-start;
        flex-direction: column;
    }

    .chart-item {
        grid-template-columns: 65px minmax(0, 1fr) 35px;
        gap: 7px;
    }
}
</style>

<div class="dashboard-page">

    <!-- JUDUL -->
    <div class="dashboard-heading">
        <h1>Ringkasan pemilihan</h1>

        <p>
            <?= e($periodeAktif['nama_periode'] ?? 'Pemilihan Kakang Senduk Ponorogo') ?>
        </p>
    </div>

    <!-- STATUS VOTING -->
    <div class="status-voting <?= $votingAktif ? 'aktif' : 'nonaktif' ?>">

        <div>
            <strong>
                <?= $votingAktif ? '● Voting aktif' : '● Voting tidak aktif' ?>
            </strong>

            <small>
                <?php if ($periodeAktif): ?>
                    <?= tanggalDashboard($periodeAktif['tanggal_mulai']) ?>
                    –
                    <?= tanggalDashboard($periodeAktif['tanggal_selesai']) ?>

                    &nbsp; • &nbsp;

                    <?= $votingAktif
                        ? 'Voting sedang berlangsung'
                        : 'Voting belum tersedia atau sudah berakhir'
                    ?>
                <?php else: ?>
                    Belum ada periode voting yang dibuat.
                <?php endif; ?>
            </small>
        </div>

        <a href="periode.php">Kelola periode</a>

    </div>

    <!-- KARTU STATISTIK -->
    <div class="dashboard-stats">

        <div class="stat-card">
            <span class="stat-label">Total kontestan</span>

            <h2><?= number_format($totalKontestan, 0, ',', '.') ?></h2>

            <small>
                <?= count($dataKakang) ?> Kakang -
                <?= count($dataSenduk) ?> Senduk
            </small>
        </div>

        <div class="stat-card">
            <span class="stat-label">Pemilih terdaftar</span>

            <h2><?= number_format($totalPemilih, 0, ',', '.') ?></h2>

            <small>Akun pemilih terdaftar</small>
        </div>

        <div class="stat-card">
            <span class="stat-label">Suara Kakang</span>

            <h2><?= number_format($totalSuaraKakang, 0, ',', '.') ?></h2>

            <small>
                <?= $totalSuara > 0
                    ? round(($totalSuaraKakang / $totalSuara) * 100) . '% dari seluruh suara'
                    : 'Belum ada suara masuk'
                ?>
            </small>
        </div>

        <div class="stat-card">
            <span class="stat-label">Suara Senduk</span>

            <h2><?= number_format($totalSuaraSenduk, 0, ',', '.') ?></h2>

            <small>
                <?= $totalSuara > 0
                    ? round(($totalSuaraSenduk / $totalSuara) * 100) . '% dari seluruh suara'
                    : 'Belum ada suara masuk'
                ?>
            </small>
        </div>

    </div>

    <!-- GRAFIK DAN TERATAS -->
    <div class="dashboard-panels">

        <!-- PEROLEHAN SUARA -->
        <div class="dashboard-panel">

            <div class="panel-heading">
                <h3>Perolehan suara</h3>
                <p>Kakang &amp; Senduk - Jumlah suara</p>
            </div>

            <div class="chart-list">

                <?php foreach ($dataKakang as $item): ?>

                    <?php
                    $persen = $suaraKakang > 0
                        ? ($item['jumlah_suara'] / $suaraKakang) * 100
                        : 0;
                    ?>

                    <div class="chart-item">

                        <span class="chart-name" title="<?= e($item['nama']) ?>">
                            <?= e($item['nama']) ?>
                        </span>

                        <div class="chart-track">
                            <div
                                class="chart-bar"
                                style="width: <?= $persen ?>%">
                            </div>
                        </div>

                        <span class="chart-value">
                            <?= number_format($item['jumlah_suara'], 0, ',', '.') ?>
                        </span>

                    </div>

                <?php endforeach; ?>

                <?php foreach ($dataSenduk as $item): ?>

                    <?php
                    $persen = $suaraSenduk > 0
                        ? ($item['jumlah_suara'] / $suaraSenduk) * 100
                        : 0;
                    ?>

                    <div class="chart-item">

                        <span class="chart-name" title="<?= e($item['nama']) ?>">
                            <?= e($item['nama']) ?>
                        </span>

                        <div class="chart-track">
                            <div
                                class="chart-bar senduk"
                                style="width: <?= $persen ?>%">
                            </div>
                        </div>

                        <span class="chart-value">
                            <?= number_format($item['jumlah_suara'], 0, ',', '.') ?>
                        </span>

                    </div>

                <?php endforeach; ?>

                <?php if (empty($dataKakang) && empty($dataSenduk)): ?>
                    <p class="text-muted small">
                        Belum ada data kontestan.
                    </p>
                <?php endif; ?>

            </div>

        </div>

        <!-- TERATAS SEMENTARA -->
        <div class="dashboard-panel">

            <div class="panel-heading">
                <h3>Teratas sementara</h3>
            </div>

            <div class="winner-section">

                <span class="winner-category">KAKANG</span>

                <?php if ($teratasKakang): ?>

                    <div class="winner-name">
                        <?= e($teratasKakang['nama']) ?>
                    </div>

                    <div class="winner-info">
                        <?= number_format($teratasKakang['jumlah_suara'], 0, ',', '.') ?>
                        suara &nbsp; • &nbsp;
                        <?= $persenKakang ?>%
                    </div>

                <?php else: ?>

                    <div class="winner-empty">
                        Belum ada kontestan.
                    </div>

                <?php endif; ?>

            </div>

            <div class="winner-section">

                <span class="winner-category">SENDUK</span>

                <?php if ($teratasSenduk): ?>

                    <div class="winner-name">
                        <?= e($teratasSenduk['nama']) ?>
                    </div>

                    <div class="winner-info">
                        <?= number_format($teratasSenduk['jumlah_suara'], 0, ',', '.') ?>
                        suara &nbsp; • &nbsp;
                        <?= $persenSenduk ?>%
                    </div>

                <?php else: ?>

                    <div class="winner-empty">
                        Belum ada kontestan.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- INFORMASI -->
    <div class="dashboard-note">

        <strong>
            Satu akun hanya dapat memilih satu kali pada setiap kategori.
        </strong>

        <p>
            Suara bersifat final. Voting hanya tersedia selama periode aktif.
        </p>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

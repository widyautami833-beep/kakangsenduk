
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

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$judul_halaman = 'Hasil Voting';

/* =========================================
   DAFTAR PERIODE
========================================= */

$daftarPeriode = $pdo->query("
    SELECT *
    FROM periode_voting
    ORDER BY id DESC
")->fetchAll();

$periodeId = isset($_GET['periode_id'])
    ? (int) $_GET['periode_id']
    : (int) ($daftarPeriode[0]['id'] ?? 0);

$periodeDipilih = null;

foreach ($daftarPeriode as $periode) {
    if ((int) $periode['id'] === $periodeId) {
        $periodeDipilih = $periode;
        break;
    }
}

// Jika ID periode tidak ditemukan, gunakan periode terbaru
if (!$periodeDipilih && !empty($daftarPeriode)) {
    $periodeDipilih = $daftarPeriode[0];
    $periodeId = (int) $periodeDipilih['id'];
}

/* =========================================
   FUNGSI AMBIL HASIL VOTING
========================================= */

function ambilHasilVoting($pdo, $periodeId, $kategori)
{
    $stmt = $pdo->prepare("
        SELECT
            k.id,
            k.nomor_peserta,
            k.nama,
            k.kategori,
            k.foto,
            k.asal_instansi,
            COUNT(v.id) AS jumlah_suara
        FROM kontestan k
        LEFT JOIN voting v
            ON v.kontestan_id = k.id
            AND v.periode_id = ?
            AND v.kategori = ?
        WHERE k.kategori = ?
        GROUP BY
            k.id,
            k.nomor_peserta,
            k.nama,
            k.kategori,
            k.foto,
            k.asal_instansi
        ORDER BY jumlah_suara DESC, k.nama ASC
    ");

    $stmt->execute([
        $periodeId,
        $kategori,
        $kategori
    ]);

    return $stmt->fetchAll();
}

/* =========================================
   DATA HASIL KAKANG DAN SENDUK
========================================= */

$dataKakang = [];
$dataSenduk = [];

if ($periodeId > 0) {
    $dataKakang = ambilHasilVoting(
        $pdo,
        $periodeId,
        'Kakang'
    );

    $dataSenduk = ambilHasilVoting(
        $pdo,
        $periodeId,
        'Senduk'
    );
}

/* =========================================
   PERHITUNGAN SUARA
========================================= */

$totalKakang = array_sum(
    array_column($dataKakang, 'jumlah_suara')
);

$totalSenduk = array_sum(
    array_column($dataSenduk, 'jumlah_suara')
);

$totalSeluruhSuara = $totalKakang + $totalSenduk;

$totalKontestan = count($dataKakang) + count($dataSenduk);

function hitungPersentase($suara, $total)
{
    if ($total <= 0) {
        return 0;
    }

    return round(($suara / $total) * 100, 1);
}

function formatAngka($angka)
{
    return number_format((int) $angka, 0, ',', '.');
}

function tanggalHasil($tanggal)
{
    return $tanggal
        ? date('d M Y', strtotime($tanggal))
        : '-';
}

/* =========================================
   DATA PEMILIH YANG SUDAH VOTING
========================================= */

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id)
    FROM voting
    WHERE periode_id = ?
");

$stmt->execute([$periodeId]);

$totalPemilihVoting = (int) $stmt->fetchColumn();

/* =========================================
   DATA KONTEN HALAMAN
========================================= */

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* =========================================
   HALAMAN HASIL VOTING
========================================= */

.hasil-page {
    width: 100%;
}

.hasil-heading {
    margin-bottom: 22px;
}

.hasil-heading h1 {
    color: #17385d;
    font-size: 26px;
    font-weight: 800;
    margin-bottom: 6px;
}

.hasil-heading p {
    color: #8290a2;
    font-size: 11px;
    margin: 0;
}

/* FILTER PERIODE */

.filter-periode {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    background: #fff;
    border-radius: 9px;
    padding: 15px 18px;
    margin-bottom: 18px;
}

.filter-periode label {
    color: #52677f;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 6px;
}

.filter-periode select {
    min-width: 250px;
    max-width: 100%;
    border: 1px solid #e1e8f0;
    border-radius: 6px;
    padding: 9px 12px;
    color: #29496d;
    font-size: 11px;
    outline: none;
}

.filter-periode select:focus {
    border-color: #2363b4;
    box-shadow: 0 0 0 3px rgba(35,99,180,.1);
}

.periode-info {
    color: #8290a2;
    font-size: 10px;
    text-align: right;
}

/* STATISTIK */

.hasil-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 19px;
}

.hasil-stat-card {
    background: #fff;
    padding: 17px;
    border-radius: 9px;
}

.hasil-stat-card span {
    display: block;
    color: #8290a2;
    font-size: 11px;
    margin-bottom: 10px;
}

.hasil-stat-card h2 {
    color: #17385d;
    font-size: 26px;
    font-weight: 800;
    margin: 0 0 7px;
}

.hasil-stat-card small {
    color: #91a0b1;
    font-size: 10px;
}

/* PANEL */

.hasil-panel {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
}

.hasil-panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 19px;
}

.hasil-panel-heading h3 {
    color: #17385d;
    font-size: 16px;
    font-weight: 800;
    margin: 0 0 5px;
}

.hasil-panel-heading p {
    color: #8997a8;
    font-size: 10px;
    margin: 0;
}

/* KATEGORI */

.kategori-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #17385d;
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 15px;
}

.kategori-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #2363b4;
}

.kategori-dot.senduk {
    background: #b68d48;
}

/* TABEL HASIL */

.table-hasil {
    width: 100%;
    border-collapse: collapse;
}

.table-hasil th {
    padding: 12px 10px;
    background: #f5f8fc;
    color: #71839a;
    font-size: 10px;
    font-weight: 700;
    text-align: left;
    white-space: nowrap;
}

.table-hasil td {
    padding: 13px 10px;
    border-bottom: 1px solid #edf1f6;
    color: #52677f;
    font-size: 11px;
    vertical-align: middle;
}

.table-hasil tbody tr:last-child td {
    border-bottom: none;
}

.nomor-urutan {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 25px;
    height: 25px;
    border-radius: 6px;
    background: #edf3fb;
    color: #285b91;
    font-weight: 800;
    font-size: 10px;
}

.nomor-urutan.juara {
    background: #f8f0df;
    color: #a17b39;
}

.nama-kontestan {
    color: #29496d;
    font-weight: 700;
}

.nomor-peserta {
    color: #8997a8;
    font-size: 10px;
}

.jumlah-suara {
    color: #17385d;
    font-weight: 800;
}

.persentase {
    color: #52677f;
    font-weight: 700;
}

/* BAR PERSENTASE */

.progress-hasil {
    height: 8px;
    min-width: 80px;
    background: #edf1f6;
    border-radius: 10px;
    overflow: hidden;
}

.progress-hasil-fill {
    height: 100%;
    background: #2363b4;
    border-radius: 10px;
}

.progress-hasil-fill.senduk {
    background: #b68d48;
}

/* JUARA */

.juara-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.juara-card {
    background: #fff;
    border-radius: 10px;
    padding: 19px;
    border-left: 4px solid #2363b4;
}

.juara-card.senduk {
    border-left-color: #b68d48;
}

.juara-card small {
    color: #b08c4e;
    font-size: 9px;
    font-weight: 800;
}

.juara-card h3 {
    color: #17385d;
    font-size: 15px;
    font-weight: 800;
    margin: 10px 0 6px;
}

.juara-card p {
    color: #8290a2;
    font-size: 10px;
    margin: 0;
}

.juara-card .juara-suara {
    display: block;
    color: #29496d;
    font-size: 21px;
    font-weight: 800;
    margin-top: 12px;
}

/* KOSONG */

.hasil-kosong {
    text-align: center;
    padding: 35px 15px;
    color: #8997a8;
    font-size: 12px;
    background: #fff;
    border-radius: 9px;
}

.hasil-kosong strong {
    display: block;
    color: #52677f;
    margin-bottom: 7px;
}

/* RESPONSIVE */

@media(max-width: 1000px) {
    .hasil-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 650px) {
    .filter-periode {
        align-items: flex-start;
        flex-direction: column;
    }

    .filter-periode select {
        width: 100%;
        min-width: 0;
    }

    .periode-info {
        text-align: left;
    }

    .juara-grid {
        grid-template-columns: 1fr;
    }

    .hasil-panel {
        padding: 14px;
    }

    .table-responsive-hasil {
        overflow-x: auto;
    }

    .hasil-heading h1 {
        font-size: 22px;
    }

    .hasil-stat-card {
        padding: 13px;
    }

    .hasil-stat-card h2 {
        font-size: 22px;
    }
}
</style>

<div class="hasil-page">

    <!-- JUDUL -->
    <div class="hasil-heading">
        <h1>Hasil Voting</h1>

        <p>
            Rekapitulasi perolehan suara Pemilihan Kakang Senduk Ponorogo.
        </p>
    </div>

    <?php if (!$periodeDipilih): ?>

        <div class="hasil-kosong">
            <strong>Belum ada periode voting</strong>
            Silakan buat periode voting terlebih dahulu.
            <br><br>
            <a href="periode.php" class="btn btn-primary btn-sm">
                Kelola Periode
            </a>
        </div>

    <?php else: ?>

        <!-- FILTER PERIODE -->
        <div class="filter-periode">

            <form method="GET" action="hasil.php">

                <label for="periode_id">
                    Pilih periode voting
                </label>

                <br>

                <select
                    name="periode_id"
                    id="periode_id"
                    onchange="this.form.submit()">

                    <?php foreach ($daftarPeriode as $periode): ?>

                        <option
                            value="<?= (int) $periode['id'] ?>"
                            <?= (int) $periode['id'] === $periodeId ? 'selected' : '' ?>>

                            <?= e($periode['nama_periode']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </form>

            <div class="periode-info">
                <?= e($periodeDipilih['nama_periode']) ?>
                <br>
                <?= tanggalHasil($periodeDipilih['tanggal_mulai']) ?>
                -
                <?= tanggalHasil($periodeDipilih['tanggal_selesai']) ?>
            </div>

        </div>

        <!-- STATISTIK -->
        <div class="hasil-stats">

            <div class="hasil-stat-card">
                <span>Total kontestan</span>
                <h2><?= formatAngka($totalKontestan) ?></h2>
                <small>
                    <?= count($dataKakang) ?> Kakang -
                    <?= count($dataSenduk) ?> Senduk
                </small>
            </div>

            <div class="hasil-stat-card">
                <span>Pemilih yang sudah voting</span>
                <h2><?= formatAngka($totalPemilihVoting) ?></h2>
                <small>Akun unik pada periode ini</small>
            </div>

            <div class="hasil-stat-card">
                <span>Total suara Kakang</span>
                <h2><?= formatAngka($totalKakang) ?></h2>
                <small>Suara kategori Kakang</small>
            </div>

            <div class="hasil-stat-card">
                <span>Total suara Senduk</span>
                <h2><?= formatAngka($totalSenduk) ?></h2>
                <small>Suara kategori Senduk</small>
            </div>

        </div>

        <!-- JUARA SEMENTARA -->
        <div class="juara-grid">

            <div class="juara-card">

                <small>KAKANG - PERINGKAT 1</small>

                <?php if (!empty($dataKakang)): ?>

                    <h3><?= e($dataKakang[0]['nama']) ?></h3>

                    <p>
                        Nomor peserta:
                        <?= e($dataKakang[0]['nomor_peserta']) ?>
                    </p>

                    <span class="juara-suara">
                        <?= formatAngka($dataKakang[0]['jumlah_suara']) ?>
                        suara
                    </span>

                    <p>
                        <?= hitungPersentase(
                            $dataKakang[0]['jumlah_suara'],
                            $totalKakang
                        ) ?>% dari suara Kakang
                    </p>

                <?php else: ?>

                    <h3>Belum ada kontestan</h3>
                    <p>Belum tersedia data Kakang.</p>

                <?php endif; ?>

            </div>

            <div class="juara-card senduk">

                <small>SENDUK - PERINGKAT 1</small>

                <?php if (!empty($dataSenduk)): ?>

                    <h3><?= e($dataSenduk[0]['nama']) ?></h3>

                    <p>
                        Nomor peserta:
                        <?= e($dataSenduk[0]['nomor_peserta']) ?>
                    </p>

                    <span class="juara-suara">
                        <?= formatAngka($dataSenduk[0]['jumlah_suara']) ?>
                        suara
                    </span>

                    <p>
                        <?= hitungPersentase(
                            $dataSenduk[0]['jumlah_suara'],
                            $totalSenduk
                        ) ?>% dari suara Senduk
                    </p>

                <?php else: ?>

                    <h3>Belum ada kontestan</h3>
                    <p>Belum tersedia data Senduk.</p>

                <?php endif; ?>

            </div>

        </div>

        <!-- HASIL KAKANG -->
        <div class="hasil-panel">

            <div class="hasil-panel-heading">
                <div>
                    <h3>Hasil Voting Kakang</h3>
                    <p>
                        Peringkat dan perolehan suara seluruh kontestan Kakang.
                    </p>
                </div>
            </div>

            <?php if (!empty($dataKakang)): ?>

                <div class="table-responsive-hasil">

                    <table class="table-hasil">

                        <thead>
                            <tr>
                                <th>Peringkat</th>
                                <th>Kontestan</th>
                                <th>Instansi</th>
                                <th>Jumlah suara</th>
                                <th>Persentase</th>
                                <th>Grafik</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($dataKakang as $index => $item): ?>

                                <?php
                                $persen = hitungPersentase(
                                    $item['jumlah_suara'],
                                    $totalKakang
                                );
                                ?>

                                <tr>

                                    <td>
                                        <span class="nomor-urutan <?= $index === 0 ? 'juara' : '' ?>">
                                            <?= $index + 1 ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="nama-kontestan">
                                            <?= e($item['nama']) ?>
                                        </div>

                                        <div class="nomor-peserta">
                                            <?= e($item['nomor_peserta']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= e($item['asal_instansi'] ?? '-') ?>
                                    </td>

                                    <td class="jumlah-suara">
                                        <?= formatAngka($item['jumlah_suara']) ?>
                                    </td>

                                    <td class="persentase">
                                        <?= $persen ?>%
                                    </td>

                                    <td>
                                        <div class="progress-hasil">
                                            <div
                                                class="progress-hasil-fill"
                                                style="width: <?= $persen ?>%">
                                            </div>
                                        </div>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="hasil-kosong">
                    Belum ada data kontestan Kakang.
                </div>

            <?php endif; ?>

        </div>

        <!-- HASIL SENDUK -->
        <div class="hasil-panel">

            <div class="hasil-panel-heading">
                <div>
                    <h3>Hasil Voting Senduk</h3>
                    <p>
                        Peringkat dan perolehan suara seluruh kontestan Senduk.
                    </p>
                </div>
            </div>

            <?php if (!empty($dataSenduk)): ?>

                <div class="table-responsive-hasil">

                    <table class="table-hasil">

                        <thead>
                            <tr>
                                <th>Peringkat</th>
                                <th>Kontestan</th>
                                <th>Instansi</th>
                                <th>Jumlah suara</th>
                                <th>Persentase</th>
                                <th>Grafik</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($dataSenduk as $index => $item): ?>

                                <?php
                                $persen = hitungPersentase(
                                    $item['jumlah_suara'],
                                    $totalSenduk
                                );
                                ?>

                                <tr>

                                    <td>
                                        <span class="nomor-urutan <?= $index === 0 ? 'juara' : '' ?>">
                                            <?= $index + 1 ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="nama-kontestan">
                                            <?= e($item['nama']) ?>
                                        </div>

                                        <div class="nomor-peserta">
                                            <?= e($item['nomor_peserta']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= e($item['asal_instansi'] ?? '-') ?>
                                    </td>

                                    <td class="jumlah-suara">
                                        <?= formatAngka($item['jumlah_suara']) ?>
                                    </td>

                                    <td class="persentase">
                                        <?= $persen ?>%
                                    </td>

                                    <td>
                                        <div class="progress-hasil">
                                            <div
                                                class="progress-hasil-fill senduk"
                                                style="width: <?= $persen ?>%">
                                            </div>
                                        </div>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="hasil-kosong">
                    Belum ada data kontestan Senduk.
                </div>

            <?php endif; ?>

        </div>

        <!-- CATATAN -->
        <div class="dashboard-note" style="
            background: #eaf1fa;
            padding: 15px 18px;
            border-radius: 9px;
        ">

            <strong style="
                display: block;
                color: #29496d;
                font-size: 11px;
                margin-bottom: 6px;
            ">
                Informasi hasil voting
            </strong>

            <p style="
                color: #8292a5;
                font-size: 10px;
                margin: 0;
            ">
                Hasil ditampilkan berdasarkan data suara yang tersimpan
                pada periode yang dipilih. Peringkat pertama merupakan
                kontestan dengan jumlah suara terbanyak dan bukan
                penetapan pemenang resmi.
            </p>

        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

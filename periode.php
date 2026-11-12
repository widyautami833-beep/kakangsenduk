
<?php
session_start();

require_once __DIR__ . '/../config/koneksi.php';

// Cek hak akses admin.
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

$pesan = '';
$jenisPesan = 'success';

// Token CSRF.
if (empty($_SESSION['csrf_periode'])) {
    $_SESSION['csrf_periode'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| PROSES FORM PERIODE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['csrf_periode']) ||
        !hash_equals($_SESSION['csrf_periode'], $token)
    ) {
        $pesan = 'Permintaan tidak valid. Silakan muat ulang halaman.';
        $jenisPesan = 'danger';

    } else {

        $aksi = $_POST['aksi'] ?? '';

        try {

            /*
            |--------------------------------------------------------------------------
            | TAMBAH ATAU EDIT PERIODE
            |--------------------------------------------------------------------------
            */

            if ($aksi === 'simpan') {

                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $namaPeriode = trim($_POST['nama_periode'] ?? '');
                $tanggalMulai = trim($_POST['tanggal_mulai'] ?? '');
                $tanggalSelesai = trim($_POST['tanggal_selesai'] ?? '');

                if (
                    $namaPeriode === '' ||
                    $tanggalMulai === '' ||
                    $tanggalSelesai === ''
                ) {
                    throw new Exception('Semua kolom wajib diisi.');
                }

                $formatTanggal = 'Y-m-d\TH:i';

                $mulai = DateTime::createFromFormat(
                    $formatTanggal,
                    $tanggalMulai
                );

                $selesai = DateTime::createFromFormat(
                    $formatTanggal,
                    $tanggalSelesai
                );

                if (
                    !$mulai ||
                    !$selesai ||
                    $mulai->format($formatTanggal) !== $tanggalMulai ||
                    $selesai->format($formatTanggal) !== $tanggalSelesai
                ) {
                    throw new Exception('Format tanggal tidak valid.');
                }

                if ($selesai <= $mulai) {
                    throw new Exception(
                        'Tanggal selesai harus setelah tanggal mulai.'
                    );
                }

                $tanggalMulaiDB = $mulai->format('Y-m-d H:i:s');
                $tanggalSelesaiDB = $selesai->format('Y-m-d H:i:s');

                if ($id) {

                    // Pastikan periode yang diedit tersedia.
                    $stmtCek = $pdo->prepare("
                        SELECT id
                        FROM periode_voting
                        WHERE id = ?
                    ");

                    $stmtCek->execute([$id]);

                    if (!$stmtCek->fetch()) {
                        throw new Exception('Periode tidak ditemukan.');
                    }

                    // Cegah perubahan jadwal yang membuat data voting
                    // menjadi berada di luar periode.
                    $stmtVoting = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM voting
                        WHERE periode_id = ?
                    ");

                    $stmtVoting->execute([$id]);
                    $jumlahSuara = (int) $stmtVoting->fetchColumn();

                    if ($jumlahSuara > 0) {
                        $stmtRentang = $pdo->prepare("
                            SELECT
                                MIN(created_at) AS suara_pertama,
                                MAX(created_at) AS suara_terakhir
                            FROM voting
                            WHERE periode_id = ?
                        ");

                        $stmtRentang->execute([$id]);
                        $rentang = $stmtRentang->fetch();

                        if (
                            $tanggalMulaiDB > $rentang['suara_pertama'] ||
                            $tanggalSelesaiDB < $rentang['suara_terakhir']
                        ) {
                            throw new Exception(
                                'Jadwal tidak boleh diubah melewati waktu suara yang sudah tersimpan.'
                            );
                        }
                    }

                    $stmt = $pdo->prepare("
                        UPDATE periode_voting
                        SET
                            nama_periode = ?,
                            tanggal_mulai = ?,
                            tanggal_selesai = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $namaPeriode,
                        $tanggalMulaiDB,
                        $tanggalSelesaiDB,
                        $id
                    ]);

                    $pesan = 'Periode pemilihan berhasil diperbarui.';

                } else {

                    // Periode baru dibuat dalam keadaan Nonaktif.
                    $stmt = $pdo->prepare("
                        INSERT INTO periode_voting
                        (
                            nama_periode,
                            tanggal_mulai,
                            tanggal_selesai,
                            status
                        )
                        VALUES (?, ?, ?, 'Nonaktif')
                    ");

                    $stmt->execute([
                        $namaPeriode,
                        $tanggalMulaiDB,
                        $tanggalSelesaiDB
                    ]);

                    $pesan = 'Periode pemilihan berhasil ditambahkan.';
                }

            /*
            |--------------------------------------------------------------------------
            | AKTIFKAN PERIODE
            |--------------------------------------------------------------------------
            */

            } elseif ($aksi === 'aktifkan') {

                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$id) {
                    throw new Exception('ID periode tidak valid.');
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM periode_voting
                    WHERE id = ?
                ");

                $stmt->execute([$id]);
                $periode = $stmt->fetch();

                if (!$periode) {
                    throw new Exception('Periode tidak ditemukan.');
                }

                $sekarang = date('Y-m-d H:i:s');

                if ($sekarang < $periode['tanggal_mulai']) {
                    throw new Exception(
                        'Periode belum mencapai tanggal mulai.'
                    );
                }

                if ($sekarang > $periode['tanggal_selesai']) {
                    throw new Exception(
                        'Periode sudah melewati tanggal selesai.'
                    );
                }

                // Hanya satu periode yang boleh aktif.
                $pdo->beginTransaction();

                $pdo->exec("
                    UPDATE periode_voting
                    SET status = 'Nonaktif'
                ");

                $stmtAktif = $pdo->prepare("
                    UPDATE periode_voting
                    SET status = 'Aktif'
                    WHERE id = ?
                ");

                $stmtAktif->execute([$id]);

                $pdo->commit();

                $pesan = 'Periode voting berhasil diaktifkan.';

            /*
            |--------------------------------------------------------------------------
            | NONAKTIFKAN PERIODE
            |--------------------------------------------------------------------------
            */

            } elseif ($aksi === 'nonaktifkan') {

                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$id) {
                    throw new Exception('ID periode tidak valid.');
                }

                $stmt = $pdo->prepare("
                    UPDATE periode_voting
                    SET status = 'Nonaktif'
                    WHERE id = ?
                ");

                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    $stmtCek = $pdo->prepare("
                        SELECT id
                        FROM periode_voting
                        WHERE id = ?
                    ");

                    $stmtCek->execute([$id]);

                    if (!$stmtCek->fetch()) {
                        throw new Exception('Periode tidak ditemukan.');
                    }
                }

                $pesan = 'Periode voting berhasil dinonaktifkan.';

            } else {
                throw new Exception('Aksi tidak dikenali.');
            }

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $pesan = $e->getMessage();
            $jenisPesan = 'danger';

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $pesan = 'Terjadi kesalahan database saat memproses periode.';
            $jenisPesan = 'danger';
        }
    }
}

/*
|--------------------------------------------------------------------------
| DATA UNTUK FORM EDIT
|--------------------------------------------------------------------------
*/

$modeEdit = false;

$dataEdit = [
    'id' => '',
    'nama_periode' => '',
    'tanggal_mulai' => '',
    'tanggal_selesai' => ''
];

if (isset($_GET['edit'])) {

    $idEdit = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($idEdit) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM periode_voting
            WHERE id = ?
        ");

        $stmt->execute([$idEdit]);
        $hasilEdit = $stmt->fetch();

        if ($hasilEdit) {

            $modeEdit = true;

            $dataEdit = [
                'id' => $hasilEdit['id'],
                'nama_periode' => $hasilEdit['nama_periode'],
                'tanggal_mulai' => date(
                    'Y-m-d\TH:i',
                    strtotime($hasilEdit['tanggal_mulai'])
                ),
                'tanggal_selesai' => date(
                    'Y-m-d\TH:i',
                    strtotime($hasilEdit['tanggal_selesai'])
                )
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DAFTAR PERIODE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        p.*,
        (
            SELECT COUNT(*)
            FROM voting v
            WHERE v.periode_id = p.id
        ) AS total_suara
    FROM periode_voting p
    ORDER BY p.id DESC
");

$daftarPeriode = $stmt->fetchAll();

$judul_halaman = 'Periode Voting';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.periode-page {
    padding-bottom: 30px;
}

.periode-heading {
    margin-bottom: 25px;
}

.periode-heading h1 {
    color: #102e50;
    font-size: 27px;
    font-weight: 800;
    margin-bottom: 7px;
}

.periode-heading p {
    color: #7d8b9b;
    font-size: 14px;
    margin: 0;
}

.periode-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 15px;
    padding: 24px;
    box-shadow: 0 4px 18px rgba(16,46,80,.03);
    margin-bottom: 25px;
}

.periode-card-title {
    color: #102e50;
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 22px;
}

.periode-form .form-label {
    color: #40546b;
    font-size: 13px;
    font-weight: 700;
}

.periode-form .form-control {
    border: 1px solid #dfe6ef;
    border-radius: 9px;
    padding: 11px 13px;
    font-size: 14px;
}

.periode-form .form-control:focus {
    border-color: #1d5ca8;
    box-shadow: 0 0 0 3px rgba(29,92,168,.1);
}

.btn-periode-primary {
    background: #1d5ca8;
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 11px 20px;
    font-weight: 700;
}

.btn-periode-primary:hover {
    background: #102e50;
    color: #fff;
}

.periode-table {
    width: 100%;
    border-collapse: collapse;
}

.periode-table th {
    background: #f7f9fc;
    color: #8491a0;
    font-size: 11px;
    text-transform: uppercase;
    padding: 14px;
    white-space: nowrap;
}

.periode-table td {
    color: #40546b;
    font-size: 13px;
    padding: 15px 14px;
    border-bottom: 1px solid #edf0f4;
    vertical-align: middle;
}

.badge-periode {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.periode-aktif {
    background: #e8f7ef;
    color: #258653;
}

.periode-nonaktif {
    background: #edf1f6;
    color: #66778a;
}

.periode-belum {
    background: #fff4e8;
    color: #b8792f;
}

.periode-selesai {
    background: #f1edff;
    color: #7656c9;
}

.btn-periode-edit {
    background: #e9f2ff;
    color: #1d5ca8;
    border: 0;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.btn-periode-aktif {
    background: #e8f7ef;
    color: #258653;
    border: 0;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
}

.btn-periode-nonaktif {
    background: #fff0f0;
    color: #c34b4b;
    border: 0;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
}

@media(max-width: 768px) {
    .periode-card {
        padding: 17px;
    }

    .periode-heading h1 {
        font-size: 23px;
    }
}
</style>

<div class="periode-page">

    <!-- JUDUL -->
    <div class="periode-heading">
        <h1>Periode Voting</h1>
        <p>Atur jadwal dan status pelaksanaan pemilihan Kakang Senduk Ponorogo.</p>
    </div>

    <!-- NOTIFIKASI -->
    <?php if ($pesan): ?>
        <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show">
            <?= e($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH / EDIT -->
    <div class="periode-card">

        <h5 class="periode-card-title">
            <?= $modeEdit ? 'Edit Periode Pemilihan' : 'Tambah Periode Pemilihan' ?>
        </h5>

        <form method="POST" class="periode-form">

            <input type="hidden" name="aksi" value="simpan">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_periode']) ?>">

            <input
                type="hidden"
                name="id"
                value="<?= e((string) $dataEdit['id']) ?>">

            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label">Nama Periode</label>

                    <input
                        type="text"
                        name="nama_periode"
                        class="form-control"
                        placeholder="Contoh: Pemilihan Kakang Senduk Ponorogo 2026"
                        value="<?= e($dataEdit['nama_periode']) ?>"
                        required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tanggal dan Waktu Mulai</label>

                    <input
                        type="datetime-local"
                        name="tanggal_mulai"
                        class="form-control"
                        value="<?= e($dataEdit['tanggal_mulai']) ?>"
                        required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tanggal dan Waktu Selesai</label>

                    <input
                        type="datetime-local"
                        name="tanggal_selesai"
                        class="form-control"
                        value="<?= e($dataEdit['tanggal_selesai']) ?>"
                        required>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">

                    <button
                        type="submit"
                        class="btn btn-periode-primary">
                        <?= $modeEdit ? 'Simpan Perubahan' : 'Tambah Periode' ?>
                    </button>

                    <?php if ($modeEdit): ?>
                        <a href="periode.php" class="btn btn-light border">
                            Batal Edit
                        </a>
                    <?php endif; ?>

                </div>

            </div>

        </form>

    </div>

    <!-- DAFTAR PERIODE -->
    <div class="periode-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

            <h5 class="periode-card-title mb-0">
                Daftar Periode Pemilihan
            </h5>

            <span class="text-muted small">
                <?= count($daftarPeriode) ?> periode
            </span>

        </div>

        <div class="table-responsive">

            <table class="periode-table">

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Periode</th>
                        <th>Tanggal Mulai</th>
                        <th>Tanggal Selesai</th>
                        <th>Total Suara</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($daftarPeriode) > 0): ?>

                        <?php foreach ($daftarPeriode as $index => $item): ?>

                            <?php
                            $sekarang = date('Y-m-d H:i:s');

                            if ($item['status'] === 'Aktif' &&
                                $sekarang >= $item['tanggal_mulai'] &&
                                $sekarang <= $item['tanggal_selesai']) {
                                $labelStatus = 'Sedang Berlangsung';
                                $classStatus = 'periode-aktif';

                            } elseif ($sekarang < $item['tanggal_mulai']) {
                                $labelStatus = 'Belum Dimulai';
                                $classStatus = 'periode-belum';

                            } elseif ($sekarang > $item['tanggal_selesai']) {
                                $labelStatus = 'Selesai';
                                $classStatus = 'periode-selesai';

                            } else {
                                $labelStatus = 'Nonaktif';
                                $classStatus = 'periode-nonaktif';
                            }
                            ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>
                                    <strong><?= e($item['nama_periode']) ?></strong>
                                </td>

                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['tanggal_mulai'])) ?>
                                </td>

                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['tanggal_selesai'])) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $item['total_suara']) ?>
                                </td>

                                <td>
                                    <span class="badge-periode <?= $classStatus ?>">
                                        <?= e($labelStatus) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="d-flex gap-2 flex-wrap">

                                        <a
                                            href="periode.php?edit=<?= (int) $item['id'] ?>"
                                            class="btn-periode-edit">
                                            Edit
                                        </a>

                                        <?php if ($item['status'] === 'Aktif'): ?>

                                            <form method="POST"
                                                onsubmit="return confirm('Yakin ingin menonaktifkan periode ini?')">

                                                <input type="hidden" name="aksi" value="nonaktifkan">

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $item['id'] ?>">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_periode']) ?>">

                                                <button type="submit" class="btn-periode-nonaktif">
                                                    Nonaktifkan
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <form method="POST"
                                                onsubmit="return confirm('Aktifkan periode pemilihan ini?')">

                                                <input type="hidden" name="aksi" value="aktifkan">

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $item['id'] ?>">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_periode']) ?>">

                                                <button type="submit" class="btn-periode-aktif">
                                                    Aktifkan
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                Belum ada periode pemilihan.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

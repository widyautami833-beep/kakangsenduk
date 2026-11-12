
<?php
session_start();

require_once __DIR__ . '/../config/koneksi.php';

// Pastikan hanya admin yang dapat mengakses halaman ini.
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
if (empty($_SESSION['csrf_pemilih'])) {
    $_SESSION['csrf_pemilih'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| PROSES HAPUS PEMILIH
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['csrf_pemilih']) ||
        !hash_equals($_SESSION['csrf_pemilih'], $token)
    ) {
        $pesan = 'Permintaan tidak valid. Silakan muat ulang halaman.';
        $jenisPesan = 'danger';

    } else {

        $aksi = $_POST['aksi'] ?? '';

        if ($aksi === 'hapus') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id) {
                $pesan = 'ID pemilih tidak valid.';
                $jenisPesan = 'danger';

            } elseif ($id === (int) $_SESSION['user_id']) {
                $pesan = 'Admin tidak dapat menghapus akun yang sedang digunakan.';
                $jenisPesan = 'danger';

            } else {

                try {
                    // Pastikan akun yang akan dihapus adalah pemilih.
                    $stmtCek = $pdo->prepare("
                        SELECT id, nama
                        FROM users
                        WHERE id = ?
                          AND role = 'pemilih'
                        LIMIT 1
                    ");

                    $stmtCek->execute([$id]);
                    $pemilih = $stmtCek->fetch();

                    if (!$pemilih) {
                        throw new Exception('Data pemilih tidak ditemukan.');
                    }

                    // Periksa apakah pemilih sudah memberikan suara.
                    $stmtVoting = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM voting
                        WHERE user_id = ?
                    ");

                    $stmtVoting->execute([$id]);
                    $jumlahVoting = (int) $stmtVoting->fetchColumn();

                    if ($jumlahVoting > 0) {
                        throw new Exception(
                            'Akun tidak dapat dihapus karena sudah memiliki riwayat voting. Data suara harus tetap terjaga.'
                        );
                    }

                    // Hapus akun pemilih.
                    $stmtHapus = $pdo->prepare("
                        DELETE FROM users
                        WHERE id = ?
                          AND role = 'pemilih'
                    ");

                    $stmtHapus->execute([$id]);

                    if ($stmtHapus->rowCount() > 0) {
                        $pesan = 'Akun pemilih berhasil dihapus.';
                    } else {
                        throw new Exception('Akun gagal dihapus.');
                    }

                } catch (Exception $e) {
                    $pesan = $e->getMessage();
                    $jenisPesan = 'danger';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| PENCARIAN PEMILIH
|--------------------------------------------------------------------------
*/

$keyword = trim($_GET['cari'] ?? '');

$sql = "
    SELECT
        u.id,
        u.nama,
        u.email,
        u.no_hp,
        u.created_at,

        COUNT(v.id) AS total_suara,

        COUNT(
            DISTINCT CASE
                WHEN v.kategori = 'Kakang' THEN v.kategori
            END
        ) AS sudah_kakang,

        COUNT(
            DISTINCT CASE
                WHEN v.kategori = 'Senduk' THEN v.kategori
            END
        ) AS sudah_senduk

    FROM users u

    LEFT JOIN voting v
        ON u.id = v.user_id

    WHERE u.role = 'pemilih'
";

$params = [];

if ($keyword !== '') {
    $sql .= "
        AND (
            u.nama LIKE ?
            OR u.email LIKE ?
            OR u.no_hp LIKE ?
        )
    ";

    $cari = "%{$keyword}%";

    $params = [$cari, $cari, $cari];
}

$sql .= "
    GROUP BY
        u.id,
        u.nama,
        u.email,
        u.no_hp,
        u.created_at

    ORDER BY u.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$daftarPemilih = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| STATISTIK PEMILIH
|--------------------------------------------------------------------------
*/

$stmtTotal = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'pemilih'
");

$totalPemilih = (int) $stmtTotal->fetchColumn();

$stmtSudahVoting = $pdo->query("
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    INNER JOIN voting v
        ON u.id = v.user_id
    WHERE u.role = 'pemilih'
");

$totalSudahVoting = (int) $stmtSudahVoting->fetchColumn();

$totalBelumVoting = max(0, $totalPemilih - $totalSudahVoting);

$judul_halaman = 'Data Pemilih';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.pemilih-page {
    padding-bottom: 30px;
}

.pemilih-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.pemilih-heading h1 {
    color: #102e50;
    font-size: 27px;
    font-weight: 800;
    margin-bottom: 7px;
}

.pemilih-heading p {
    color: #7d8b9b;
    font-size: 14px;
    margin: 0;
}

.pemilih-stat {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 14px;
    padding: 21px;
    height: 100%;
    box-shadow: 0 4px 18px rgba(16,46,80,.03);
}

.pemilih-stat small {
    color: #7d8b9b;
    font-size: 13px;
}

.pemilih-stat h3 {
    color: #102e50;
    font-size: 28px;
    font-weight: 800;
    margin: 10px 0 0;
}

.pemilih-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 15px;
    padding: 23px;
    box-shadow: 0 4px 18px rgba(16,46,80,.03);
}

.pemilih-card-title {
    color: #102e50;
    font-size: 18px;
    font-weight: 800;
}

.pemilih-search {
    display: flex;
    gap: 9px;
    max-width: 450px;
}

.pemilih-search input {
    border: 1px solid #dfe6ef;
    border-radius: 9px;
    padding: 10px 13px;
    font-size: 13px;
}

.pemilih-search button {
    border: 0;
    border-radius: 9px;
    background: #1d5ca8;
    color: #fff;
    padding: 10px 17px;
    font-size: 13px;
    font-weight: 700;
}

.pemilih-table {
    width: 100%;
    border-collapse: collapse;
}

.pemilih-table th {
    background: #f7f9fc;
    color: #8491a0;
    font-size: 11px;
    text-transform: uppercase;
    padding: 14px;
    white-space: nowrap;
}

.pemilih-table td {
    padding: 15px 14px;
    border-bottom: 1px solid #edf0f4;
    color: #40546b;
    font-size: 13px;
    vertical-align: middle;
}

.pemilih-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e9f2ff;
    color: #1d5ca8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    flex-shrink: 0;
}

.status-voting {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.status-sudah {
    background: #e8f7ef;
    color: #258653;
}

.status-belum {
    background: #fff4e8;
    color: #b8792f;
}

.btn-hapus-pemilih {
    background: #fff0f0;
    color: #c34b4b;
    border: 0;
    padding: 7px 11px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
}

.btn-hapus-pemilih:hover {
    background: #c34b4b;
    color: #fff;
}

@media(max-width: 768px) {
    .pemilih-card {
        padding: 16px;
    }

    .pemilih-heading h1 {
        font-size: 23px;
    }

    .pemilih-search {
        width: 100%;
        max-width: none;
    }
}
</style>

<div class="pemilih-page">

    <!-- JUDUL HALAMAN -->
    <div class="pemilih-heading">
        <div>
            <h1>Data Pemilih</h1>
            <p>Kelola dan pantau akun pengguna yang terdaftar sebagai pemilih.</p>
        </div>
    </div>

    <!-- NOTIFIKASI -->
    <?php if ($pesan): ?>
        <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show">
            <?= e($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATISTIK -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-4">
            <div class="pemilih-stat">
                <small>Total Pemilih Terdaftar</small>
                <h3><?= number_format($totalPemilih) ?></h3>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="pemilih-stat">
                <small>Sudah Memberikan Suara</small>
                <h3><?= number_format($totalSudahVoting) ?></h3>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="pemilih-stat">
                <small>Belum Memberikan Suara</small>
                <h3><?= number_format($totalBelumVoting) ?></h3>
            </div>
        </div>

    </div>

    <!-- TABEL DATA PEMILIH -->
    <div class="pemilih-card">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>
                <h5 class="pemilih-card-title mb-1">Daftar Akun Pemilih</h5>
                <small class="text-muted">
                    Menampilkan <?= count($daftarPemilih) ?> data pemilih
                </small>
            </div>

            <form method="GET" class="pemilih-search">

                <input
                    type="text"
                    name="cari"
                    class="form-control"
                    placeholder="Cari nama, email, atau nomor HP..."
                    value="<?= e($keyword) ?>">

                <button type="submit">Cari</button>

                <?php if ($keyword !== ''): ?>
                    <a href="pemilih.php" class="btn btn-light border">
                        Reset
                    </a>
                <?php endif; ?>

            </form>

        </div>

        <div class="table-responsive">

            <table class="pemilih-table">

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Pemilih</th>
                        <th>Email</th>
                        <th>No. HP</th>
                        <th>Tanggal Daftar</th>
                        <th>Status Voting</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($daftarPemilih) > 0): ?>

                        <?php foreach ($daftarPemilih as $index => $pemilih): ?>

                            <?php
                            $sudahVoting =
                                (int) $pemilih['sudah_kakang'] > 0 &&
                                (int) $pemilih['sudah_senduk'] > 0;
                            ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>
                                    <div class="d-flex align-items-center gap-2">

                                        <div class="pemilih-avatar">
                                            <?= strtoupper(substr($pemilih['nama'], 0, 1)) ?>
                                        </div>

                                        <strong>
                                            <?= e($pemilih['nama']) ?>
                                        </strong>

                                    </div>
                                </td>

                                <td><?= e($pemilih['email']) ?></td>

                                <td>
                                    <?= e($pemilih['no_hp'] ?: '-') ?>
                                </td>

                                <td>
                                    <?= $pemilih['created_at']
                                        ? date('d/m/Y', strtotime($pemilih['created_at']))
                                        : '-' ?>
                                </td>

                                <td>

                                    <?php if ($sudahVoting): ?>

                                        <span class="status-voting status-sudah">
                                            Sudah Voting
                                        </span>

                                    <?php elseif (
                                        (int) $pemilih['total_suara'] > 0
                                    ): ?>

                                        <span class="status-voting status-belum">
                                            Sebagian
                                        </span>

                                    <?php else: ?>

                                        <span class="status-voting status-belum">
                                            Belum Voting
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Yakin ingin menghapus akun pemilih ini?')">

                                        <input
                                            type="hidden"
                                            name="aksi"
                                            value="hapus">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_pemilih']) ?>">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $pemilih['id'] ?>">

                                        <button
                                            type="submit"
                                            class="btn-hapus-pemilih">
                                            Hapus
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                Data pemilih tidak ditemukan.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

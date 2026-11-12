
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
$modeEdit = false;
$dataEdit = [
    'id' => '',
    'nomor_peserta' => '',
    'nama' => '',
    'kategori' => 'Kakang',
    'foto' => '',
    'asal_instansi' => '',
    'visi' => '',
    'misi' => '',
    'deskripsi' => ''
];

// Token CSRF.
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}

// Folder penyimpanan foto.
$folderUpload = __DIR__ . '/../uploads/';

if (!is_dir($folderUpload)) {
    mkdir($folderUpload, 0755, true);
}

// Fungsi membersihkan nama file.
function namaFileAman($nama)
{
    $ekstensi = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
    return bin2hex(random_bytes(12)) . '.' . $ekstensi;
}

// Fungsi menghapus foto lama dari folder uploads.
function hapusFotoKontestan($namaFoto)
{
    if (!$namaFoto) {
        return;
    }

    $path = __DIR__ . '/../uploads/' . basename($namaFoto);

    if (is_file($path)) {
        unlink($path);
    }
}

// Proses tambah, edit, dan hapus.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !isset($_SESSION['csrf_admin']) ||
        !hash_equals($_SESSION['csrf_admin'], $token)
    ) {
        $pesan = 'Permintaan tidak valid. Silakan muat ulang halaman.';
        $jenisPesan = 'danger';

    } else {

        $aksi = $_POST['aksi'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | HAPUS KONTENSTAN
        |--------------------------------------------------------------------------
        */

        if ($aksi === 'hapus') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id) {
                $pesan = 'Data kontestan tidak valid.';
                $jenisPesan = 'danger';

            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT foto
                        FROM kontestan
                        WHERE id = ?
                    ");
                    $stmt->execute([$id]);
                    $kontestan = $stmt->fetch();

                    if (!$kontestan) {
                        throw new Exception('Kontestan tidak ditemukan.');
                    }

                    // Cek apakah kontestan sudah memiliki suara.
                    $stmtCek = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM voting
                        WHERE kontestan_id = ?
                    ");
                    $stmtCek->execute([$id]);

                    if ((int) $stmtCek->fetchColumn() > 0) {
                        throw new Exception(
                            'Kontestan tidak dapat dihapus karena sudah memiliki suara.'
                        );
                    }

                    $stmtHapus = $pdo->prepare("
                        DELETE FROM kontestan
                        WHERE id = ?
                    ");
                    $stmtHapus->execute([$id]);

                    hapusFotoKontestan($kontestan['foto']);

                    $pesan = 'Data kontestan berhasil dihapus.';

                } catch (Exception $e) {
                    $pesan = $e->getMessage();
                    $jenisPesan = 'danger';
                }
            }

        /*
        |--------------------------------------------------------------------------
        | TAMBAH DAN EDIT KONTENSTAN
        |--------------------------------------------------------------------------
        */

        } elseif ($aksi === 'simpan') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $nomorPeserta = trim($_POST['nomor_peserta'] ?? '');
            $nama = trim($_POST['nama'] ?? '');
            $kategori = $_POST['kategori'] ?? '';
            $asalInstansi = trim($_POST['asal_instansi'] ?? '');
            $visi = trim($_POST['visi'] ?? '');
            $misi = trim($_POST['misi'] ?? '');
            $deskripsi = trim($_POST['deskripsi'] ?? '');

            $fotoBaru = '';
            $fotoLama = '';

            try {
                if (
                    $nomorPeserta === '' ||
                    $nama === '' ||
                    $asalInstansi === '' ||
                    $visi === '' ||
                    $misi === ''
                ) {
                    throw new Exception('Semua kolom wajib harus diisi.');
                }

                if (!in_array($kategori, ['Kakang', 'Senduk'], true)) {
                    throw new Exception('Kategori kontestan tidak valid.');
                }

                // Periksa apakah nomor peserta sudah digunakan.
                $stmtNomor = $pdo->prepare("
                    SELECT id
                    FROM kontestan
                    WHERE nomor_peserta = ?
                      AND id != ?
                    LIMIT 1
                ");
                $stmtNomor->execute([$nomorPeserta, $id ?: 0]);

                if ($stmtNomor->fetch()) {
                    throw new Exception('Nomor peserta sudah digunakan.');
                }

                // Ambil foto lama jika sedang mengedit.
                if ($id) {
                    $stmtLama = $pdo->prepare("
                        SELECT foto
                        FROM kontestan
                        WHERE id = ?
                    ");
                    $stmtLama->execute([$id]);
                    $dataLama = $stmtLama->fetch();

                    if (!$dataLama) {
                        throw new Exception('Data kontestan tidak ditemukan.');
                    }

                    $fotoLama = $dataLama['foto'];
                }

                // Proses upload foto jika ada file baru.
                if (
                    isset($_FILES['foto']) &&
                    $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE
                ) {
                    if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
                        throw new Exception('Upload foto gagal.');
                    }

                    if ($_FILES['foto']['size'] > 2 * 1024 * 1024) {
                        throw new Exception('Ukuran foto maksimal 2 MB.');
                    }

                    $mime = (new finfo(FILEINFO_MIME_TYPE))
                        ->file($_FILES['foto']['tmp_name']);

                    $daftarMime = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($daftarMime[$mime])) {
                        throw new Exception(
                            'Format foto harus JPG, PNG, atau WEBP.'
                        );
                    }

                    $namaFoto = bin2hex(random_bytes(12))
                        . '.' . $daftarMime[$mime];

                    $tujuanFoto = $folderUpload . $namaFoto;

                    if (!move_uploaded_file(
                        $_FILES['foto']['tmp_name'],
                        $tujuanFoto
                    )) {
                        throw new Exception('Foto gagal disimpan.');
                    }

                    $fotoBaru = $namaFoto;
                }

                $foto = $fotoBaru ?: $fotoLama;

                if ($id) {
                    // Update data kontestan.
                    $stmt = $pdo->prepare("
                        UPDATE kontestan
                        SET
                            nomor_peserta = ?,
                            nama = ?,
                            kategori = ?,
                            foto = ?,
                            asal_instansi = ?,
                            visi = ?,
                            misi = ?,
                            deskripsi = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $nomorPeserta,
                        $nama,
                        $kategori,
                        $foto,
                        $asalInstansi,
                        $visi,
                        $misi,
                        $deskripsi,
                        $id
                    ]);

                    if ($fotoBaru && $fotoLama) {
                        hapusFotoKontestan($fotoLama);
                    }

                    $pesan = 'Data kontestan berhasil diperbarui.';

                } else {
                    // Tambah data kontestan.
                    $stmt = $pdo->prepare("
                        INSERT INTO kontestan
                        (
                            nomor_peserta,
                            nama,
                            kategori,
                            foto,
                            asal_instansi,
                            visi,
                            misi,
                            deskripsi
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $nomorPeserta,
                        $nama,
                        $kategori,
                        $foto,
                        $asalInstansi,
                        $visi,
                        $misi,
                        $deskripsi
                    ]);

                    $pesan = 'Kontestan baru berhasil ditambahkan.';
                }

            } catch (Exception $e) {

                // Hapus foto baru jika penyimpanan data gagal.
                if ($fotoBaru) {
                    hapusFotoKontestan($fotoBaru);
                }

                $pesan = $e->getMessage();
                $jenisPesan = 'danger';
            }

        } else {
            $pesan = 'Aksi tidak dikenali.';
            $jenisPesan = 'danger';
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA UNTUK FORM EDIT
|--------------------------------------------------------------------------
*/

if (isset($_GET['edit'])) {
    $idEdit = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($idEdit) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM kontestan
            WHERE id = ?
        ");
        $stmt->execute([$idEdit]);
        $hasilEdit = $stmt->fetch();

        if ($hasilEdit) {
            $modeEdit = true;
            $dataEdit = $hasilEdit;
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL SELURUH DATA KONTENSTAN
|--------------------------------------------------------------------------
*/

$filter = $_GET['kategori'] ?? 'Semua';

$sql = "SELECT * FROM kontestan";
$params = [];

if (in_array($filter, ['Kakang', 'Senduk'], true)) {
    $sql .= " WHERE kategori = ?";
    $params[] = $filter;
}

$sql .= "
    ORDER BY
        CASE WHEN kategori = 'Kakang' THEN 1 ELSE 2 END,
        nomor_peserta ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kontestan = $stmt->fetchAll();

// Statistik.
$totalKontestan = count($kontestan);

$judul_halaman = 'Data Kontestan';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.admin-page {
    background: #f5f8fc;
    min-height: 100vh;
    padding: 35px 0 60px;
}

.admin-page-title {
    color: #102e50;
    font-size: 29px;
    font-weight: 800;
    margin-bottom: 7px;
}

.admin-page-subtitle {
    color: #7d8b9b;
    margin-bottom: 28px;
}

.admin-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 5px 20px rgba(16,46,80,.035);
    margin-bottom: 25px;
}

.admin-card-title {
    color: #102e50;
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 22px;
}

.form-label {
    color: #40546b;
    font-size: 13px;
    font-weight: 700;
}

.form-control,
.form-select {
    border: 1px solid #dfe6ef;
    border-radius: 9px;
    padding: 11px 13px;
    font-size: 14px;
}

.form-control:focus,
.form-select:focus {
    border-color: #1d5ca8;
    box-shadow: 0 0 0 3px rgba(29,92,168,.1);
}

.btn-admin-primary {
    background: #1d5ca8;
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 11px 20px;
    font-weight: 700;
}

.btn-admin-primary:hover {
    background: #102e50;
    color: #fff;
}

.table-admin {
    width: 100%;
    border-collapse: collapse;
}

.table-admin th {
    background: #f7f9fc;
    color: #8491a0;
    font-size: 12px;
    padding: 14px;
    text-transform: uppercase;
    white-space: nowrap;
}

.table-admin td {
    color: #40546b;
    font-size: 13px;
    padding: 14px;
    border-bottom: 1px solid #edf0f4;
    vertical-align: middle;
}

.foto-kontestan {
    width: 55px;
    height: 65px;
    object-fit: cover;
    border-radius: 8px;
    background: #edf2f8;
}

.kategori-kakang {
    background: #e9f2ff;
    color: #1d5ca8;
}

.kategori-senduk {
    background: #fbf4e7;
    color: #a67b31;
}

.badge-kategori {
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.action-button {
    border: none;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    display: inline-block;
}

.btn-edit {
    color: #1d5ca8;
    background: #e9f2ff;
}

.btn-delete {
    color: #c34b4b;
    background: #fff0f0;
}

.preview-foto {
    width: 100px;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
    margin-bottom: 12px;
}

@media(max-width: 768px) {
    .admin-card {
        padding: 18px;
    }

    .admin-page-title {
        font-size: 24px;
    }
}
</style>

<main class="admin-page">
    <div class="container">

        <h1 class="admin-page-title">Data Kontestan</h1>
        <p class="admin-page-subtitle">
            Kelola informasi peserta Kakang dan Senduk Ponorogo.
        </p>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show">
                <?= e($pesan) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- FORM TAMBAH DAN EDIT -->
        <div class="admin-card">

            <h5 class="admin-card-title">
                <?= $modeEdit ? 'Edit Data Kontestan' : 'Tambah Kontestan Baru' ?>
            </h5>

            <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="aksi" value="simpan">

                <input type="hidden" name="csrf_token"
                    value="<?= e($_SESSION['csrf_admin']) ?>">

                <input type="hidden" name="id"
                    value="<?= e((string) $dataEdit['id']) ?>">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Nomor Peserta</label>
                        <input
                            type="text"
                            name="nomor_peserta"
                            class="form-control"
                            placeholder="Contoh: K-003 atau S-003"
                            value="<?= e($dataEdit['nomor_peserta']) ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nama Kontestan</label>
                        <input
                            type="text"
                            name="nama"
                            class="form-control"
                            placeholder="Masukkan nama lengkap"
                            value="<?= e($dataEdit['nama']) ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Kategori</label>
                        <select name="kategori" class="form-select" required>
                            <option value="Kakang"
                                <?= $dataEdit['kategori'] === 'Kakang' ? 'selected' : '' ?>>
                                Kakang
                            </option>

                            <option value="Senduk"
                                <?= $dataEdit['kategori'] === 'Senduk' ? 'selected' : '' ?>>
                                Senduk
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Asal Instansi</label>
                        <input
                            type="text"
                            name="asal_instansi"
                            class="form-control"
                            placeholder="Nama sekolah atau instansi"
                            value="<?= e($dataEdit['asal_instansi']) ?>"
                            required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Foto Kontestan</label>
                        <input
                            type="file"
                            name="foto"
                            class="form-control"
                            accept=".jpg,.jpeg,.png,.webp">

                        <small class="text-muted">
                            Format JPG, PNG, atau WEBP. Maksimal 2 MB.
                        </small>
                    </div>

                    <?php if ($modeEdit && $dataEdit['foto']): ?>
                        <div class="col-12">
                            <p class="form-label mb-2">Foto Saat Ini</p>
                            <img
                                src="../uploads/<?= e($dataEdit['foto']) ?>"
                                class="preview-foto"
                                alt="Foto kontestan">
                        </div>
                    <?php endif; ?>

                    <div class="col-12">
                        <label class="form-label">Visi</label>
                        <textarea
                            name="visi"
                            class="form-control"
                            rows="3"
                            placeholder="Masukkan visi kontestan"
                            required><?= e($dataEdit['visi']) ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Misi</label>
                        <textarea
                            name="misi"
                            class="form-control"
                            rows="4"
                            placeholder="Masukkan misi kontestan"
                            required><?= e($dataEdit['misi']) ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea
                            name="deskripsi"
                            class="form-control"
                            rows="4"
                            placeholder="Informasi tambahan tentang kontestan"><?= e($dataEdit['deskripsi']) ?></textarea>
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-2">

                        <button type="submit" class="btn btn-admin-primary">
                            <?= $modeEdit ? 'Simpan Perubahan' : 'Tambah Kontestan' ?>
                        </button>

                        <?php if ($modeEdit): ?>
                            <a href="kontestan.php" class="btn btn-light border">
                                Batal Edit
                            </a>
                        <?php endif; ?>

                    </div>

                </div>
            </form>
        </div>

        <!-- DAFTAR KONTENSTAN -->
        <div class="admin-card">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <h5 class="admin-card-title mb-0">Daftar Kontestan</h5>

                <span class="text-muted small">
                    <?= $totalKontestan ?> data ditampilkan
                </span>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">

                <a href="kontestan.php"
                    class="btn btn-sm <?= $filter === 'Semua' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Semua
                </a>

                <a href="kontestan.php?kategori=Kakang"
                    class="btn btn-sm <?= $filter === 'Kakang' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Kakang
                </a>

                <a href="kontestan.php?kategori=Senduk"
                    class="btn btn-sm <?= $filter === 'Senduk' ? 'btn-primary' : 'btn-outline-primary' ?>">
                    Senduk
                </a>

            </div>

            <div class="table-responsive">
                <table class="table-admin">

                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Foto</th>
                            <th>Nomor Peserta</th>
                            <th>Nama Kontestan</th>
                            <th>Kategori</th>
                            <th>Asal Instansi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($kontestan) > 0): ?>

                            <?php foreach ($kontestan as $index => $item): ?>

                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <?php if ($item['foto']): ?>
                                            <img
                                                src="../uploads/<?= e($item['foto']) ?>"
                                                class="foto-kontestan"
                                                alt="Foto kontestan">
                                        <?php else: ?>
                                            <div class="foto-kontestan d-flex align-items-center justify-content-center">
                                                <span class="text-muted small">Foto</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong><?= e($item['nomor_peserta']) ?></strong>
                                    </td>

                                    <td>
                                        <?= e($item['nama']) ?>
                                    </td>

                                    <td>
                                        <?php if ($item['kategori'] === 'Kakang'): ?>
                                            <span class="badge-kategori kategori-kakang">
                                                Kakang
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-kategori kategori-senduk">
                                                Senduk
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e($item['asal_instansi']) ?>
                                    </td>

                                    <td>
                                        <div class="d-flex gap-2">

                                            <a
                                                href="kontestan.php?edit=<?= (int) $item['id'] ?>"
                                                class="action-button btn-edit">
                                                Edit
                                            </a>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Yakin ingin menghapus kontestan ini?')">

                                                <input type="hidden" name="aksi" value="hapus">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_admin']) ?>">

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int) $item['id'] ?>">

                                                <button type="submit" class="action-button btn-delete">
                                                    Hapus
                                                </button>

                                            </form>

                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Belum ada data kontestan.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

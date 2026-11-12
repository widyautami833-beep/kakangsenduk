
<?php
require_once __DIR__ . '/config/koneksi.php';

$nama = 'Administrator';
$email = 'admin@kakangsenduk.com';
$password = 'AdminKakang2026!';
$role = 'admin';

// Cek apakah akun admin sudah tersedia.
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);

if ($stmt->fetch()) {
    die("Akun admin sudah tersedia. Silakan langsung login.");
}

// Enkripsi password.
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Simpan akun admin.
$stmt = $pdo->prepare("
    INSERT INTO users (nama, email, password, role)
    VALUES (?, ?, ?, ?)
");

$stmt->execute([
    $nama,
    $email,
    $passwordHash,
    $role
]);

echo "Akun admin berhasil dibuat.<br>";
echo "Email: " . htmlspecialchars($email) . "<br>";
echo "Password: " . htmlspecialchars($password);

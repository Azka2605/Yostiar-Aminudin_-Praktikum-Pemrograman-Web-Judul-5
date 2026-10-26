<?php
/**
 * Praktikum 5: Endpoint REST API Mahasiswa Berbasis MySQL & PDO
 * File: api/mahasiswa.php
 * Mendukung metode HTTP GET, POST, PUT, DELETE dengan respon format JSON.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendJson(500, [
        'status' => 'error',
        'message' => 'Gagal terhubung ke database.',
        'data' => null
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = null;

if (isset($_GET['id'])) {
    $validatedId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($validatedId === false || $validatedId < 1) {
        sendJson(400, [
            'status' => 'error',
            'message' => 'ID mahasiswa harus berupa bilangan bulat positif.',
            'data' => null
        ]);
        exit;
    }
    $id = $validatedId;
}

switch ($method) {
    case 'GET':
        handleGet($pdo, $id);
        break;
    case 'POST':
        handlePost($pdo);
        break;
    case 'PUT':
        handlePut($pdo, $id);
        break;
    case 'DELETE':
        handleDelete($pdo, $id);
        break;
    default:
        header('Allow: GET, POST, PUT, DELETE, OPTIONS');
        sendJson(405, [
            'status' => 'error',
            'message' => "Metode HTTP {$method} tidak diizinkan.",
            'data' => null
        ]);
}

function sendJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function readInput(): ?array
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (is_array($input)) {
        return $input;
    }

    if ($rawInput === '' && !empty($_POST)) {
        return $_POST;
    }

    return null;
}

function getStudentFields(array $input): ?array
{
    $npm = trim((string)($input['npm'] ?? ''));
    $nama = trim((string)($input['nama'] ?? ''));
    $programStudi = trim((string)($input['program_studi'] ?? ''));
    $angkatan = filter_var($input['angkatan'] ?? null, FILTER_VALIDATE_INT);
    $email = trim((string)($input['email'] ?? ''));

    if (
        $npm === '' ||
        $nama === '' ||
        $programStudi === '' ||
        $angkatan === false ||
        $angkatan < 1 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        return null;
    }

    return [
        'npm' => $npm,
        'nama' => $nama,
        'program_studi' => $programStudi,
        'angkatan' => $angkatan,
        'email' => $email
    ];
}

function handleGet(PDO $pdo, ?int $id): void
{
    try {
        if ($id !== null) {
            $stmt = $pdo->prepare(
                'SELECT id, npm, nama, program_studi, angkatan, email, created_at, updated_at
                 FROM mahasiswa WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $mahasiswa = $stmt->fetch();

            if (!$mahasiswa) {
                sendJson(404, [
                    'status' => 'error',
                    'message' => "Mahasiswa dengan ID {$id} tidak ditemukan.",
                    'data' => null
                ]);
                return;
            }

            sendJson(200, ['status' => 'success', 'data' => $mahasiswa]);
            return;
        }

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, npm, nama, program_studi, angkatan, email, created_at, updated_at
                 FROM mahasiswa
                 WHERE nama LIKE :nama_search OR npm LIKE :npm_search
                 ORDER BY id DESC'
            );
            $keyword = "%{$search}%";
            $stmt->execute([
                ':nama_search' => $keyword,
                ':npm_search' => $keyword
            ]);
        } else {
            $stmt = $pdo->query(
                'SELECT id, npm, nama, program_studi, angkatan, email, created_at, updated_at
                 FROM mahasiswa ORDER BY id DESC'
            );
        }

        $result = $stmt->fetchAll();
        sendJson(200, [
            'status' => 'success',
            'total' => count($result),
            'data' => $result
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        sendJson(500, [
            'status' => 'error',
            'message' => 'Gagal mengambil data mahasiswa.',
            'data' => null
        ]);
    }
}

function handlePost(PDO $pdo): void
{
    $input = readInput();
    $student = $input === null ? null : getStudentFields($input);

    if ($student === null) {
        sendJson(400, [
            'status' => 'error',
            'message' => 'Data tidak valid. NPM, nama, program studi, angkatan positif, dan email yang valid wajib diisi.',
            'data' => null
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mahasiswa (npm, nama, program_studi, angkatan, email)
             VALUES (:npm, :nama, :program_studi, :angkatan, :email)'
        );
        $stmt->execute($student);

        $student['id'] = (int)$pdo->lastInsertId();
        sendJson(201, [
            'status' => 'success',
            'message' => 'Data mahasiswa berhasil ditambahkan.',
            'data' => $student
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        sendJson(400, [
            'status' => 'error',
            'message' => 'Gagal menambahkan data. Pastikan NPM belum digunakan.',
            'data' => null
        ]);
    }
}

function handlePut(PDO $pdo, ?int $id): void
{
    if ($id === null) {
        sendJson(400, [
            'status' => 'error',
            'message' => 'ID mahasiswa wajib disertakan untuk pembaruan data.',
            'data' => null
        ]);
        return;
    }

    $input = readInput();
    $student = $input === null ? null : getStudentFields($input);
    if ($student === null) {
        sendJson(400, [
            'status' => 'error',
            'message' => 'Data tidak valid. NPM, nama, program studi, angkatan positif, dan email yang valid wajib diisi.',
            'data' => null
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE mahasiswa
             SET npm = :npm, nama = :nama, program_studi = :program_studi,
                 angkatan = :angkatan, email = :email
             WHERE id = :id'
        );
        $stmt->execute([
            ':npm' => $student['npm'],
            ':nama' => $student['nama'],
            ':program_studi' => $student['program_studi'],
            ':angkatan' => $student['angkatan'],
            ':email' => $student['email'],
            ':id' => $id
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT 1 FROM mahasiswa WHERE id = :id');
            $check->execute([':id' => $id]);
            if (!$check->fetchColumn()) {
                sendJson(404, [
                    'status' => 'error',
                    'message' => "Mahasiswa dengan ID {$id} tidak ditemukan.",
                    'data' => null
                ]);
                return;
            }
        }

        sendJson(200, [
            'status' => 'success',
            'message' => 'Data mahasiswa berhasil diperbarui.',
            'data' => ['id' => $id] + $student
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        sendJson(400, [
            'status' => 'error',
            'message' => 'Gagal memperbarui data. Pastikan NPM belum digunakan.',
            'data' => null
        ]);
    }
}

function handleDelete(PDO $pdo, ?int $id): void
{
    if ($id === null) {
        sendJson(400, [
            'status' => 'error',
            'message' => 'ID mahasiswa wajib disertakan untuk penghapusan data.',
            'data' => null
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM mahasiswa WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            sendJson(404, [
                'status' => 'error',
                'message' => "Mahasiswa dengan ID {$id} tidak ditemukan.",
                'data' => null
            ]);
            return;
        }

        sendJson(200, [
            'status' => 'success',
            'message' => "Data mahasiswa dengan ID {$id} berhasil dihapus."
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        sendJson(500, [
            'status' => 'error',
            'message' => 'Gagal menghapus data mahasiswa.',
            'data' => null
        ]);
    }
}

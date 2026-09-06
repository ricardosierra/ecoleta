<?php
require_once __DIR__ . '/db.php';
$db = getDbConnection();
$stmt = $db->query("SELECT id, login, email, role FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update password for 'admin' or create it if not exists.
$newPass = password_hash('Admin123!', PASSWORD_DEFAULT);
$updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE login = 'admin'");
$updateStmt->execute([$newPass]);

apiSendJsonHeaders();
echo json_encode(['users' => $users, 'updated' => $updateStmt->rowCount()]);

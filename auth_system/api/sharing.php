<?php
// Catch fatal errors and return JSON instead of empty response
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'msg'=>'PHP: '.$err['message'].' (line '.$err['line'].')']);
    }
});
error_reporting(0);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'msg'=>'Not authenticated']); exit;
}
$uid = (int)$_SESSION['user_id'];
session_write_close();

include_once '../includes/db.php';
if (!$conn || $conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'msg'=>'DB connection failed']); exit;
}
header('Content-Type: application/json');

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS share_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  item_uuid VARCHAR(36) NOT NULL,
  item_title VARCHAR(255) DEFAULT 'Untitled',
  item_type VARCHAR(20) DEFAULT 'login',
  item_data MEDIUMTEXT DEFAULT NULL,
  code_hash VARCHAR(255) NOT NULL,
  recipient_id INT DEFAULT NULL,
  status ENUM('pending','code_entered','active','cancelled') DEFAULT 'pending',
  sender_confirmed TINYINT DEFAULT 0,
  recipient_confirmed TINYINT DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS device_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  requester_id INT NOT NULL,
  target_id INT DEFAULT NULL,
  code_hash VARCHAR(255) NOT NULL,
  device_label VARCHAR(100) DEFAULT 'My Device',
  status ENUM('pending','code_entered','active','cancelled') DEFAULT 'pending',
  requester_confirmed TINYINT DEFAULT 0,
  target_confirmed TINYINT DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add new columns only if they don't exist yet (error 1060 = duplicate column)
$_migrations = [
    "ALTER TABLE share_requests ADD COLUMN item_type VARCHAR(20) DEFAULT 'login'",
    "ALTER TABLE share_requests ADD COLUMN item_data MEDIUMTEXT DEFAULT NULL",
];
foreach ($_migrations as $_sql) {
    try { $conn->query($_sql); } catch (mysqli_sql_exception $e) {
        if ($e->getCode() !== 1060) { throw $e; }
    }
}
unset($_migrations, $_sql);

function db_prepare($conn, $sql) {
    $s = $conn->prepare($sql);
    if (!$s) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'msg'=>'Query error: '.$conn->error]);
        exit;
    }
    return $s;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

  case 'create_share':
    $uuid  = $_POST['uuid'] ?? '';
    $title = trim($_POST['title'] ?? 'Untitled');
    if (!$uuid) { echo json_encode(['ok'=>false,'msg'=>'Missing uuid']); exit; }

    $stmt = db_prepare($conn, "SELECT encrypted_data,type FROM vault_entries WHERE uuid=? AND user_id=?");
    $stmt->bind_param('si', $uuid, $uid);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$entry) { echo json_encode(['ok'=>false,'msg'=>'Item not found']); exit; }

    $code      = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $code_hash = password_hash($code, PASSWORD_BCRYPT);
    $expires   = date('Y-m-d H:i:s', time() + 86400);
    $item_type = $entry['type'];
    $item_data = $entry['encrypted_data'];

    $euuid = $conn->real_escape_string($uuid);
    $conn->query("UPDATE share_requests SET status='cancelled' WHERE sender_id=$uid AND item_uuid='$euuid' AND status='pending'");

    $stmt = db_prepare($conn, "INSERT INTO share_requests (sender_id,item_uuid,item_title,item_type,item_data,code_hash,expires_at) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('issssss', $uid, $uuid, $title, $item_type, $item_data, $code_hash, $expires);
    $stmt->execute();
    $share_id = $conn->insert_id;
    $stmt->close();

    echo json_encode(['ok'=>true,'code'=>$code,'share_id'=>$share_id]);
    break;

  case 'enter_share_code':
    $code = trim($_POST['code'] ?? '');
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        echo json_encode(['ok'=>false,'msg'=>'Code must be exactly 6 digits']); exit;
    }

    $now    = date('Y-m-d H:i:s');
    $result = $conn->query("SELECT id,sender_id,item_title,code_hash FROM share_requests WHERE status='pending' AND expires_at>'$now' AND sender_id!=$uid ORDER BY created_at DESC LIMIT 50");
    $found  = null;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (password_verify($code, $row['code_hash'])) { $found = $row; break; }
        }
    }

    if (!$found) { echo json_encode(['ok'=>false,'msg'=>'Invalid or expired code']); exit; }

    $conn->query("UPDATE share_requests SET status='code_entered',recipient_id=$uid WHERE id={$found['id']}");
    $sender = $conn->query("SELECT username FROM users WHERE id={$found['sender_id']}")->fetch_assoc();

    echo json_encode(['ok'=>true,'share_id'=>$found['id'],'item_title'=>$found['item_title'],'sender_name'=>$sender['username']??'Unknown']);
    break;

  case 'confirm_share':
    $sid = (int)($_POST['share_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'Missing share_id']); exit; }

    $stmt = db_prepare($conn, "SELECT id,sender_id,recipient_id,status FROM share_requests WHERE id=? AND status IN ('code_entered','active')");
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $share = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$share || ((int)$share['sender_id'] !== $uid && (int)$share['recipient_id'] !== $uid)) {
        echo json_encode(['ok'=>false,'msg'=>'Share not found or access denied']); exit;
    }

    if ((int)$share['sender_id'] === $uid) $conn->query("UPDATE share_requests SET sender_confirmed=1 WHERE id=$sid");
    else                                   $conn->query("UPDATE share_requests SET recipient_confirmed=1 WHERE id=$sid");

    $row = $conn->query("SELECT sender_confirmed,recipient_confirmed FROM share_requests WHERE id=$sid")->fetch_assoc();
    if ($row['sender_confirmed'] && $row['recipient_confirmed']) {
        $conn->query("UPDATE share_requests SET status='active' WHERE id=$sid");
        echo json_encode(['ok'=>true,'status'=>'active','msg'=>'Share is now active! Both confirmed.']);
    } else {
        echo json_encode(['ok'=>true,'status'=>'waiting','msg'=>'Waiting for the other account to confirm.']);
    }
    break;

  case 'cancel_share':
    $sid = (int)($_POST['share_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'Missing share_id']); exit; }
    $conn->query("UPDATE share_requests SET status='cancelled' WHERE id=$sid AND (sender_id=$uid OR recipient_id=$uid)");
    echo json_encode(['ok'=>true]);
    break;

  case 'list_shared_by_me':
    $stmt = db_prepare($conn, "SELECT sr.id,sr.item_title,sr.item_type,sr.status,sr.sender_confirmed,sr.recipient_confirmed,sr.created_at,u.username as recipient_name FROM share_requests sr LEFT JOIN users u ON u.id=sr.recipient_id WHERE sr.sender_id=? AND sr.status!='cancelled' ORDER BY sr.created_at DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok'=>true,'shares'=>$rows]);
    break;

  case 'list_shared_with_me':
    $stmt = db_prepare($conn, "SELECT sr.id,sr.item_title,sr.item_type,sr.item_data,sr.status,sr.sender_confirmed,sr.recipient_confirmed,sr.created_at,u.username as sender_name FROM share_requests sr LEFT JOIN users u ON u.id=sr.sender_id WHERE sr.recipient_id=? AND sr.status!='cancelled' ORDER BY sr.created_at DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok'=>true,'shares'=>$rows]);
    break;

  /* ---- Device Connect ---- */

  case 'create_device_connect':
    $label     = trim($_POST['label'] ?? 'My Device');
    $code      = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $code_hash = password_hash($code, PASSWORD_BCRYPT);
    $expires   = date('Y-m-d H:i:s', time() + 600);

    $stmt = db_prepare($conn, "INSERT INTO device_connections (requester_id,code_hash,device_label,expires_at) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $uid, $code_hash, $label, $expires);
    $stmt->execute();
    $cid = $conn->insert_id;
    $stmt->close();

    echo json_encode(['ok'=>true,'code'=>$code,'conn_id'=>$cid]);
    break;

  case 'enter_device_code':
    $code = trim($_POST['code'] ?? '');
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        echo json_encode(['ok'=>false,'msg'=>'Code must be exactly 6 digits']); exit;
    }

    $now    = date('Y-m-d H:i:s');
    $result = $conn->query("SELECT id,requester_id,device_label,code_hash FROM device_connections WHERE status='pending' AND expires_at>'$now' AND requester_id!=$uid ORDER BY created_at DESC LIMIT 50");
    $found  = null;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (password_verify($code, $row['code_hash'])) { $found = $row; break; }
        }
    }

    if (!$found) { echo json_encode(['ok'=>false,'msg'=>'Invalid or expired code (10 min window)']); exit; }

    $conn->query("UPDATE device_connections SET status='code_entered',target_id=$uid WHERE id={$found['id']}");
    $req = $conn->query("SELECT username FROM users WHERE id={$found['requester_id']}")->fetch_assoc();

    echo json_encode(['ok'=>true,'conn_id'=>$found['id'],'requester_name'=>$req['username']??'Unknown','device_label'=>$found['device_label']]);
    break;

  case 'confirm_device':
    $cid = (int)($_POST['conn_id'] ?? 0);
    $stmt = db_prepare($conn, "SELECT id,requester_id,target_id,status FROM device_connections WHERE id=? AND status IN ('code_entered','active')");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $dc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dc || ((int)$dc['requester_id'] !== $uid && (int)$dc['target_id'] !== $uid)) {
        echo json_encode(['ok'=>false,'msg'=>'Connection not found']); exit;
    }

    if ((int)$dc['requester_id'] === $uid) $conn->query("UPDATE device_connections SET requester_confirmed=1 WHERE id=$cid");
    else                                   $conn->query("UPDATE device_connections SET target_confirmed=1 WHERE id=$cid");

    $row = $conn->query("SELECT requester_confirmed,target_confirmed FROM device_connections WHERE id=$cid")->fetch_assoc();
    if ($row['requester_confirmed'] && $row['target_confirmed']) {
        $conn->query("UPDATE device_connections SET status='active' WHERE id=$cid");
        echo json_encode(['ok'=>true,'status'=>'active','msg'=>'Devices connected! Both confirmed.']);
    } else {
        echo json_encode(['ok'=>true,'status'=>'waiting','msg'=>'Waiting for the other account to confirm.']);
    }
    break;

  case 'list_connections':
    $stmt = db_prepare($conn, "SELECT dc.id,dc.device_label,dc.status,dc.requester_id,dc.target_id,dc.requester_confirmed,dc.target_confirmed,dc.created_at,ur.username as requester_name,ut.username as target_name FROM device_connections dc LEFT JOIN users ur ON ur.id=dc.requester_id LEFT JOIN users ut ON ut.id=dc.target_id WHERE (dc.requester_id=? OR dc.target_id=?) AND dc.status!='cancelled' ORDER BY dc.created_at DESC");
    $stmt->bind_param('ii', $uid, $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok'=>true,'connections'=>$rows]);
    break;

  case 'get_connected_vault':
    $conn_id = (int)($_GET['conn_id'] ?? 0);
    if (!$conn_id) { echo json_encode(['ok'=>false,'msg'=>'Missing conn_id']); exit; }

    $stmt = db_prepare($conn, "SELECT requester_id,target_id FROM device_connections WHERE id=? AND status='active' AND (requester_id=? OR target_id=?)");
    $stmt->bind_param('iii', $conn_id, $uid, $uid);
    $stmt->execute();
    $dc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dc) { echo json_encode(['ok'=>false,'msg'=>'Connection not active or not yours']); exit; }

    $other_id = ((int)$dc['requester_id'] === $uid) ? (int)$dc['target_id'] : (int)$dc['requester_id'];

    $stmt = db_prepare($conn, "SELECT uuid,type,encrypted_data,updated_at FROM vault_entries WHERE user_id=? ORDER BY updated_at DESC");
    $stmt->bind_param('i', $other_id);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $other = $conn->query("SELECT username FROM users WHERE id=$other_id")->fetch_assoc();
    echo json_encode(['ok'=>true,'entries'=>$entries,'other_user'=>$other['username']??'Unknown']);
    break;

  default:
    echo json_encode(['ok'=>false,'msg'=>'Unknown action: '.htmlspecialchars($action)]);
}

<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}
$pdo = getDBConnection();

$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset = ($page - 1) * $limit;

$searchQuery = '';
$params = [];

if ($search !== '') {
    $searchQuery = "WHERE u.NAME LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM doctor d
    JOIN systemuser u ON d.UserID = u.UserID
    $searchQuery
");
$countStmt->execute($params);
$totalDoctors = $countStmt->fetchColumn();
$totalPages = ceil($totalDoctors / $limit);

$dataStmt = $pdo->prepare("
    SELECT d.DoctorID AS id, u.NAME AS name, d.STATUS AS status
    FROM doctor d
    JOIN systemuser u ON d.UserID = u.UserID
    $searchQuery
    ORDER BY u.NAME ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value);
}
$dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$doctors = [];

while ($row = $dataStmt->fetch()) {
    $status = strtolower($row['status']);
    if ($status === 'active') {
        $row['status'] = 'available';
    } else {
        $row['status'] = 'offline';
    }
    $doctors[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with Doctor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --success: #1cc88a;
            --danger: #e74a3b;
            --warning: #f6c23e;
            --light: #f8f9fc;
        }
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        .nav-card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            overflow: hidden;
        }
        .nav-header {
            background-color: var(--primary);
            color: white;
            padding: 1.25rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .doctor-item {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .doctor-item:hover {
            background-color: var(--light);
        }
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .status-available { background-color: var(--success); }
        .status-busy { background-color: var(--warning); }
        .status-offline { background-color: var(--secondary); }
        .btn-chat {
            background-color: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }
        .return-btn {
            background-color: var(--secondary);
            color: white;
        }
        .pagination { justify-content: center; }
        .form-inline { display: flex; gap: 10px; justify-content: center; padding: 1rem; }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100 py-4">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="nav-card">
                <div class="nav-header">
                    <h4 class="mb-0"><i class="fas fa-comment-medical me-2"></i>Select Doctor</h4>
                </div>

                <form class="form-inline" method="get">
                    <input type="text" name="search" class="form-control" placeholder="Search doctor name" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                </form>

                <div class="list-group list-group-flush">
                    <?php foreach ($doctors as $doctor): ?>
                        <div class="doctor-item">
                            <div>
                                <h5 class="mb-0"><?php echo htmlspecialchars($doctor['name']); ?></h5>
                                <small class="text-muted">
                                    <span class="status-indicator status-<?php echo $doctor['status']; ?>"></span>
                                    <?php echo ucfirst($doctor['status']); ?>
                                </small>
                            </div>
                            <a href="chat.php?doctorID=<?php echo $doctor['id']; ?>" class="btn btn-sm btn-chat">
                                <i class="fas fa-comment me-1"></i>Chat
                            </a>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($doctors)): ?>
                        <div class="text-center py-4">
                            <p class="text-muted">No doctors found.</p>
                        </div>
                    <?php endif; ?>

                    <div class="p-3 border-top text-center">
                        <a href="dashboard.php" class="btn return-btn">
                            <i class="fas fa-arrow-left me-2"></i>Return
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

            <div class="card mt-3 border-0 shadow-sm">
                <div class="card-body text-center py-2">
                    <small class="text-muted">
                        Logged in as: <strong><?php echo htmlspecialchars($_SESSION['email'] ?? 'Unknown'); ?></strong>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
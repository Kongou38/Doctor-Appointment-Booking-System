<?php
session_start();
require_once '../config.php';

// Redirect if not logged in as admin
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

// Database connection
try {
    $conn = getDBConnection();
    
    // ===== 1. KEY METRICS =====
    $metrics = [
        'total_patients' => $conn->query("SELECT COUNT(*) FROM systemuser WHERE UserID NOT IN (SELECT UserID FROM doctor) AND UserID NOT IN (SELECT UserID FROM admin)")->fetchColumn(),
        'total_doctors' => $conn->query("SELECT COUNT(*) FROM doctor WHERE STATUS = 'active'")->fetchColumn(),
        'total_appointments' => $conn->query("SELECT COUNT(*) FROM appointment")->fetchColumn(),
        'pending_appointments' => $conn->query("SELECT COUNT(*) FROM appointment WHERE STATUS = 'Pending'")->fetchColumn(),
        'total_revenue' => $conn->query("SELECT COALESCE(SUM(Amount), 0) FROM payment WHERE PaymentStatus = 'Completed'")->fetchColumn()
    ];
    
    // ===== 2. APPOINTMENT ANALYTICS =====
    // Last 30 days appointment trends
    $appointmentTrends = $conn->query("
        SELECT DATE(CreatedAt) as date, COUNT(*) as count 
        FROM appointment 
        WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(CreatedAt)
        ORDER BY date
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Appointment status distribution
    $appointmentStatus = $conn->query("
        SELECT STATUS, COUNT(*) as count 
        FROM appointment 
        GROUP BY STATUS
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 3. DOCTOR PERFORMANCE METRICS =====
    // Doctor workload (top 10)
    $doctorWorkload = $conn->query("
        SELECT d.DoctorID, u.NAME, 
               COUNT(a.AppointmentID) as appointments,
               AVG(TIMESTAMPDIFF(MINUTE, ts.StartTime, ts.EndTime)) as avg_duration,
               SUM(CASE WHEN a.STATUS = 'Completed' THEN 1 ELSE 0 END) as completed
        FROM doctor d
        JOIN systemuser u ON d.UserID = u.UserID
        LEFT JOIN appointment a ON d.DoctorID = a.DoctorID
        LEFT JOIN timeslot ts ON a.SlotID = ts.SlotID
        GROUP BY d.DoctorID, u.NAME
        ORDER BY appointments DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 4. OPERATIONAL EFFICIENCY =====
    // Time slot utilization
    $slotUtilization = $conn->query("
        SELECT DAYOFWEEK, StartTime, EndTime, 
               COUNT(a.AppointmentID) as booked,
               COUNT(a.AppointmentID)/(SELECT COUNT(*) FROM timeslot WHERE DAYOFWEEK = t.DAYOFWEEK AND StartTime = t.StartTime)*100 as utilization_rate
        FROM timeslot t
        LEFT JOIN appointment a ON t.SlotID = a.SlotID
        GROUP BY DAYOFWEEK, StartTime, EndTime
        ORDER BY utilization_rate DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Peak hours
    $peakHours = $conn->query("
        SELECT HOUR(ts.StartTime) as hour, COUNT(*) as appointments
        FROM appointment a
        JOIN timeslot ts ON a.SlotID = ts.SlotID
        GROUP BY HOUR(ts.StartTime)
        ORDER BY appointments DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // ===== 5. FINANCIAL ANALYTICS =====
    // Revenue trends (last 12 months)
    $revenueTrends = $conn->query("
        SELECT DATE_FORMAT(TransactionDate, '%Y-%m') as month, 
               SUM(Amount) as revenue
        FROM payment
        WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(TransactionDate, '%Y-%m')
        ORDER BY month
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment method distribution
    $paymentMethods = $conn->query("
        SELECT PaymentMethod, COUNT(*) as transactions, SUM(Amount) as amount
        FROM payment
        GROUP BY PaymentMethod
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Helper function to format time
function formatTime($time) {
    return date('h:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --light: #f8f9fc;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .dashboard-container {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 2rem;
            width: 100%;
            margin: 2rem auto;
        }
        
        .dashboard-header {
            color: var(--primary);
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf4;
        }
        
        .metric-card {
            border-left: 0.25rem solid var(--primary);
            border-radius: 0.35rem;
            background-color: white;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.15rem 0.5rem rgba(58, 59, 69, 0.1);
        }
        
        .metric-card.success {
            border-left-color: var(--success);
        }
        
        .metric-card.info {
            border-left-color: var(--info);
        }
        
        .metric-card.warning {
            border-left-color: var(--warning);
        }
        
        .metric-card.danger {
            border-left-color: var(--danger);
        }
        
        .metric-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--secondary);
            text-transform: uppercase;
        }
        
        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #5a5c69;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        
        .data-table {
            width: 100%;
            margin-bottom: 2rem;
        }
        
        .data-table th {
            background-color: var(--light);
            color: var(--secondary);
            font-weight: 600;
            padding: 0.75rem;
        }
        
        .data-table td {
            padding: 0.75rem;
            border-top: 1px solid #eaecf4;
        }
        
        .btn-return {
            color: var(--secondary);
            border: 1px solid #ddd;
            padding: 0.5rem 1.5rem;
        }
        
        .btn-return:hover {
            background-color: #f8f9fa;
        }
        
        .section-header {
            color: var(--primary);
            margin: 1.5rem 0 1rem;
            font-size: 1.2rem;
            font-weight: 600;
            border-bottom: 1px solid #eaecf4;
            padding-bottom: 0.5rem;
        }
        
        .progress {
            height: 1rem;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
            background-color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h4><i class="fas fa-chart-line me-2"></i>Analytics Dashboard</h4>
        </div>
        
        <div class="row">
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-title">Total Patients</div>
                    <div class="metric-value"><?= number_format($metrics['total_patients']) ?></div>
                    <i class="fas fa-users mt-2" style="color: var(--primary);"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card success">
                    <div class="metric-title">Active Doctors</div>
                    <div class="metric-value"><?= number_format($metrics['total_doctors']) ?></div>
                    <i class="fas fa-user-md mt-2" style="color: var(--success);"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card info">
                    <div class="metric-title">Total Appointments</div>
                    <div class="metric-value"><?= number_format($metrics['total_appointments']) ?></div>
                    <i class="fas fa-calendar-check mt-2" style="color: var(--info);"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card warning">
                    <div class="metric-title">Pending Appointments</div>
                    <div class="metric-value"><?= number_format($metrics['pending_appointments']) ?></div>
                    <i class="fas fa-clock mt-2" style="color: var(--warning);"></i>
                </div>
            </div>
        </div>
        
        <h5 class="section-header"><i class="fas fa-calendar-alt me-2"></i>Appointment Analytics</h5>
        
        <div class="row">
            <div class="col-md-8">
                <div class="chart-container">
                    <canvas id="appointmentTrendsChart"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-chart-pie me-2"></i>Appointment Status Distribution</h6>
                <div class="chart-container">
                    <canvas id="appointmentStatusChart"></canvas>
                </div>
            </div>
        </div>
        
        <h5 class="section-header"><i class="fas fa-user-md me-2"></i>Doctor Performance Metrics</h5>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Appointments</th>
                    <th>Completed</th>
                    <th>Avg. Duration</th>
                    <th>Completion Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($doctorWorkload as $doctor): 
                    $completionRate = $doctor['appointments'] > 0 ? ($doctor['completed'] / $doctor['appointments']) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($doctor['NAME']) ?></td>
                    <td><?= $doctor['appointments'] ?></td>
                    <td><?= $doctor['completed'] ?></td>
                    <td><?= round($doctor['avg_duration']) ?> min</td>
                    <td>
                        <div class="d-flex justify-content-between">
                            <span><?= round($completionRate) ?>%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: <?= $completionRate ?>%" 
                                 aria-valuenow="<?= $completionRate ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h5 class="section-header"><i class="fas fa-tachometer-alt me-2"></i>Operational Efficiency</h5>
        
        <div class="row">
            <div class="col-md-6">
                <h6><i class="fas fa-clock me-2"></i>Top Utilized Time Slots</h6>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Time Slot</th>
                            <th>Utilization Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slotUtilization as $slot): ?>
                        <tr>
                            <td><?= ucfirst($slot['DAYOFWEEK']) ?></td>
                            <td><?= formatTime($slot['StartTime']) ?> - <?= formatTime($slot['EndTime']) ?></td>
                            <td>
                                <div class="d-flex justify-content-between">
                                    <span><?= number_format($slot['utilization_rate'], 1) ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $slot['utilization_rate'] ?>%" 
                                         aria-valuenow="<?= $slot['utilization_rate'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <h6><i class="fas fa-chart-line me-2"></i>Peak Appointment Hours</h6>
                <div class="chart-container">
                    <canvas id="peakHoursChart"></canvas>
                </div>
            </div>
        </div>
        
        <h5 class="section-header"><i class="fas fa-money-bill-wave me-2"></i>Financial Analytics</h5>
        
        <div class="row">
            <div class="col-md-8">
                <div class="chart-container">
                    <canvas id="revenueTrendsChart"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-credit-card me-2"></i>Payment Methods</h6>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Transactions</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentMethods as $method): ?>
                        <tr>
                            <td><?= htmlspecialchars($method['PaymentMethod']) ?></td>
                            <td><?= $method['transactions'] ?></td>
                            <td>RM<?= number_format($method['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="metric-card">
                    <div class="metric-title">Total Revenue</div>
                    <div class="metric-value">RM<?= number_format($metrics['total_revenue'], 2) ?></div>
                    <i class="fas fa-coins mt-2" style="color: var(--warning);"></i>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <button class="btn btn-return">
                <i class="fas fa-arrow-left me-2"></i>Return to Dashboard
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const trendsCtx = document.getElementById('appointmentTrendsChart').getContext('2d');
        const trendsChart = new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: [<?= implode(',', array_map(function($item) { return "'" . date('M j', strtotime($item['date'])) . "'"; }, $appointmentTrends)) ?>],
                datasets: [{
                    label: 'Daily Appointments',
                    data: [<?= implode(',', array_column($appointmentTrends, 'count')) ?>],
                    backgroundColor: 'rgba(78, 115, 223, 0.1)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Appointment Trends (Last 30 Days)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        const statusCtx = document.getElementById('appointmentStatusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: [<?= implode(',', array_map(function($item) { return "'" . $item['STATUS'] . "'"; }, $appointmentStatus)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($appointmentStatus, 'count')) ?>],
                    backgroundColor: [
                        'rgba(78, 115, 223, 0.8)',
                        'rgba(28, 200, 138, 0.8)',
                        'rgba(246, 194, 62, 0.8)',
                        'rgba(231, 74, 59, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        const peakCtx = document.getElementById('peakHoursChart').getContext('2d');
        const peakChart = new Chart(peakCtx, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(function($item) { 
                    return "'" . ($item['hour'] > 12 ? $item['hour'] - 12 : $item['hour']) . 
                           ($item['hour'] >= 12 ? 'PM' : 'AM') . "'"; 
                }, $peakHours)) ?>],
                datasets: [{
                    label: 'Appointments',
                    data: [<?= implode(',', array_column($peakHours, 'appointments')) ?>],
                    backgroundColor: 'rgba(54, 185, 204, 0.5)',
                    borderColor: 'rgba(54, 185, 204, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Top 5 Busiest Hours'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        const revenueCtx = document.getElementById('revenueTrendsChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: [<?= implode(',', array_map(function($item) { 
                    $date = DateTime::createFromFormat('Y-m', $item['month']);
                    return "'" . $date->format('M Y') . "'"; 
                }, $revenueTrends)) ?>],
                datasets: [{
                    label: 'Monthly Revenue (RM)',
                    data: [<?= implode(',', array_column($revenueTrends, 'revenue')) ?>],
                    backgroundColor: 'rgba(246, 194, 62, 0.1)',
                    borderColor: 'rgba(246, 194, 62, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Revenue Trends (Last 12 Months)'
                    }
                }
            }
        });
        
        document.querySelector('.btn-return').addEventListener('click', function() {
            window.location.href = 'admin_dashboard.php';
        });
    </script>
</body>
</html>
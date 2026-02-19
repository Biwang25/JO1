

<?php
session_start();

// --- 1. AUTHENTICATION CHECK ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // For demo purposes, if not logged in, redirect or die.
    // header("Location: signin.html");
    // exit;
    // MOCK LOGIN FOR TESTING (Remove this in production)
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = 1; 
    $_SESSION['user_name'] = "Jonatan Biwang";
}

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];

// --- 2. DATABASE CONNECTION ---
$conn = new mysqli("localhost", "root", "", "invoicing_system");
if ($conn->connect_error) { die("Database connection failed: " . $conn->connect_error); }

// --- 3. HELPER FUNCTIONS ---
function logActivity($conn, $userId, $action) {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
    $stmt->bind_param("is", $userId, $action);
    $stmt->execute();
}

// Get Settings
$stmtSet = $conn->prepare("SELECT * FROM settings WHERE user_id = ?");
$stmtSet->bind_param("i", $userId);
$stmtSet->execute();
$settings = $stmtSet->get_result()->fetch_assoc();

// Default settings if none exist
if (!$settings) {
    $currency = '₱';
    $companyName = 'SmartInvoice';
    $darkMode = 0;
} else {
    $currency = $settings['currency_symbol'];
    $companyName = $settings['company_name'];
    $darkMode = $settings['dark_mode'];
}

// --- 4. FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. SAVE/UPDATE INVOICE
    if (isset($_POST['save_invoice'])) {
        $clientName = $_POST['client_name'];
        $email      = $_POST['email'];
        $invoiceNo  = $_POST['invoice_number'];
        $date       = $_POST['invoice_date']; 
        $amount     = $_POST['amount'];
        $status     = $_POST['status'];

        if (!empty($_POST['invoice_id'])) {
            $invoiceId  = $_POST['invoice_id'];
            $stmt = $conn->prepare("UPDATE invoices SET client_name=?, email=?, invoice_number=?, invoice_date=?, amount=?, status=? WHERE id=? AND user_id=?");
            $stmt->bind_param("ssssdiii", $clientName, $email, $invoiceNo, $date, $amount, $status, $invoiceId, $userId);
            $stmt->execute();
            logActivity($conn, $userId, "Updated Invoice #$invoiceNo");
        } else {
            $stmt = $conn->prepare("INSERT INTO invoices (user_id, client_name, email, invoice_number, invoice_date, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssds", $userId, $clientName, $email, $invoiceNo, $date, $amount, $status);
            $stmt->execute();
            logActivity($conn, $userId, "Created Invoice #$invoiceNo");
        }
    } 
    // B. APPROVE INVOICE
    elseif (isset($_POST['approve_invoice'])) {
        $invoiceId = $_POST['invoice_id'];
        $stmt = $conn->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $invoiceId, $userId);
        $stmt->execute();
        logActivity($conn, $userId, "Marked Invoice ID $invoiceId as Paid");
    } 
    // C. DELETE INVOICE
    elseif (isset($_POST['delete_invoice'])) {
        $invoiceId = $_POST['invoice_id'];
        $stmt = $conn->prepare("DELETE FROM invoices WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $invoiceId, $userId);
        $stmt->execute();
        logActivity($conn, $userId, "Deleted Invoice ID $invoiceId");
    }
    // D. SAVE SETTINGS
    elseif (isset($_POST['save_settings'])) {
        $newCurrency = $_POST['currency_symbol'];
        $newCompany  = $_POST['company_name'];
        $newMode     = isset($_POST['dark_mode']) ? 1 : 0;

        // Check if settings exist
        $check = $conn->query("SELECT id FROM settings WHERE user_id = $userId");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE settings SET currency_symbol=?, company_name=?, dark_mode=? WHERE user_id=?");
            $stmt->bind_param("ssii", $newCurrency, $newCompany, $newMode, $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO settings (user_id, currency_symbol, company_name, dark_mode) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $userId, $newCurrency, $newCompany, $newMode);
        }
        $stmt->execute();
        logActivity($conn, $userId, "Updated System Settings");
        header("Location: " . $_SERVER['PHP_SELF']); // Refresh to apply settings
        exit;
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- 5. DATA FETCHING ---

// Search & Filter Logic
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'All';

$sql = "SELECT * FROM invoices WHERE user_id = ?";
$types = "i";
$params = [$userId];

if (!empty($search)) {
    $sql .= " AND (client_name LIKE ? OR invoice_number LIKE ?)";
    $searchTerm = "%$search%";
    $types .= "ss";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filter !== 'All') {
    $sql .= " AND status = ?";
    $types .= "s";
    $params[] = $filter;
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Dashboard Counts
$stmtCount = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status='Paid' THEN amount ELSE 0 END),0) as paid_total,
        COALESCE(SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END),0) as pending_total,
        COALESCE(SUM(CASE WHEN status='Pending' AND invoice_date < CURRENT_DATE THEN 1 ELSE 0 END),0) as overdue_total
    FROM invoices WHERE user_id = ?
");
$stmtCount->bind_param("i", $userId);
$stmtCount->execute();
$counts = $stmtCount->get_result()->fetch_assoc();

// Chart Data (Last 6 Months Revenue)
$stmtChart = $conn->prepare("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, SUM(amount) as total 
    FROM invoices 
    WHERE user_id = ? AND status='Paid' 
    GROUP BY month 
    ORDER BY month ASC LIMIT 6
");
$stmtChart->bind_param("i", $userId);
$stmtChart->execute();
$chartRes = $stmtChart->get_result();
$chartLabels = [];
$chartValues = [];
while($row = $chartRes->fetch_assoc()) {
    $chartLabels[] = date("M Y", strtotime($row['month']));
    $chartValues[] = $row['total'];
}

// Activity Logs
$stmtLogs = $conn->prepare("SELECT action, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$stmtLogs->bind_param("i", $userId);
$stmtLogs->execute();
$logsRes = $stmtLogs->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($companyName); ?> Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Chart.js Library -->
   
    <style>
        :root {
            --bg-color: <?php echo $darkMode ? '#1f2937' : '#f4f6f9'; ?>;
            --text-color: <?php echo $darkMode ? '#f3f4f6' : '#333'; ?>;
            --card-bg: <?php echo $darkMode ? '#111827' : '#ffffff'; ?>;
            --sidebar-bg: #111827;
            --accent: #4f46e5;
            --border: <?php echo $darkMode ? '#374151' : '#eee'; ?>;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; }
        body { background: var(--bg-color); color: var(--text-color); display: flex; min-height: 100vh; transition: 0.3s; }
        
        /* Sidebar */
        .sidebar { width: 250px; background: var(--sidebar-bg); color: white; padding: 25px 20px; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar h2 { margin-bottom: 40px; font-size: 22px; display: flex; align-items: center; gap: 10px; }
        .sidebar a { display: flex; align-items: center; gap: 10px; color: #cbd5e1; text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 8px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active-link { background: #374151; color: #fff; }
        
        /* Main Content */
        .main { margin-left: 250px; padding: 30px; width: calc(100% - 250px); }
        
        /* Topbar */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .user-trigger { display: flex; align-items: center; gap: 10px; cursor: pointer; background: var(--card-bg); padding: 8px 15px; border-radius: 30px; border: 1px solid var(--border); }
        
        /* Cards Grid */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: var(--card-bg); padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .card h2 { font-size: 28px; color: var(--accent); margin-bottom: 5px; }
        .card p { color: #6b7280; font-size: 14px; }
        .card.alert h2 { color: #ef4444; }

        /* Chart & Logs Split */
        .split-section { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-container { background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border); height: 350px; }
        .logs-container { background: var(--card-bg); padding: 20px; border-radius: 12px; border: 1px solid var(--border); height: 350px; overflow-y: auto; }
        .log-item { padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .log-item span { display: block; font-size: 11px; color: #9ca3af; margin-top: 2px; }

        /* Forms & Tables */
        .section { display: none; animation: fadeIn 0.3s; }
        .section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .controls { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        input, select { padding: 10px; border-radius: 6px; border: 1px solid #d1d5db; background: var(--card-bg); color: var(--text-color); }
        button { background: var(--accent); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; transition: 0.2s; }
        button:hover { opacity: 0.9; }
        
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 12px; overflow: hidden; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: <?php echo $darkMode ? '#374151' : '#f9fafb'; ?>; font-weight: 600; font-size: 14px; }
        tr:hover { background: <?php echo $darkMode ? '#1f2937' : '#f9fafb'; ?>; }
        
        /* Badges */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fee2e2; color: #991b1b; }

        /* Action Buttons */
        .btn-sm { padding: 5px 10px; font-size: 12px; margin-right: 4px; }
        .btn-edit { background: #3b82f6; }
        .btn-del { background: #ef4444; }
        .btn-print { background: #f59e0b; }
        
        /* Settings Form */
        .settings-form { max-width: 500px; background: var(--card-bg); padding: 30px; border-radius: 12px; border: 1px solid var(--border); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input { width: 100%; }
    </style>
</head>

<body>

<!-- Sidebar -->
<div class="sidebar">
    <h2><i class="fas fa-file-invoice-dollar"></i> <?php echo htmlspecialchars($companyName); ?></h2>
    <br>
    <a href="#" onclick="showSection('dashboard')" id="link-dashboard" class="active-link"><i class="fas fa-home"></i> Dashboard</a>
    <a href="#" onclick="showSection('invoices')" id="link-invoices"><i class="fas fa-list"></i> Invoices</a>
    <a href="#" onclick="showSection('create')" id="link-create"><i class="fas fa-plus-circle"></i> Create New</a>
    <a href="#" onclick="showSection('settings')" id="link-settings"><i class="fas fa-cog"></i> Settings</a>
    
    <div style="margin-top: auto; padding-top: 20px; border-top: 1px solid #374151;">
        <a href="signout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Main Content -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">
        <h1>Overview</h1>
        <div class="user-trigger">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=4f46e5&color=fff" style="width:30px; border-radius:50%;">
            <span><?php echo htmlspecialchars($userName); ?></span>
        </div>
    </div>

    <!-- 1. DASHBOARD SECTION -->
    <div id="dashboard" class="section active">
        <div class="dashboard-grid">
            <div class="card">
                <h2><?php echo $counts['total']; ?></h2>
                <p>Total Invoices</p>
            </div>
            <div class="card">
                <h2><?php echo $currency . number_format($counts['paid_total'], 2); ?></h2>
                <p>Revenue Collected</p>
            </div>
            <div class="card">
                <h2><?php echo $counts['pending_total']; ?></h2>
                <p>Pending Invoices</p>
            </div>
            <div class="card alert">
                <h2><?php echo $counts['overdue_total']; ?></h2>
                <p>Overdue Invoices</p>
            </div>
        </div>

        <div class="split-section">
            <div class="chart-container">
                <h3>Revenue Trend </h3>
                <canvas id="revenueChart"></canvas>
            </div>
            <div class="logs-container">
                <h3>Recent Activity</h3>
                <?php while($log = $logsRes->fetch_assoc()): ?>
                    <div class="log-item">
                        <?php echo htmlspecialchars($log['action']); ?>
                        <span><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <!-- 2. INVOICES LIST SECTION -->
    <div id="invoices" class="section">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2>Invoices List</h2>
        </div>

        <!-- Search & Filter Form -->
        <form method="GET" class="controls">
            <input type="text" name="search" placeholder="Search Client or ID..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="filter" onchange="this.form.submit()">
                <option value="All" <?php if($filter=='All') echo 'selected'; ?>>All Status</option>
                <option value="Paid" <?php if($filter=='Paid') echo 'selected'; ?>>Paid</option>
                <option value="Pending" <?php if($filter=='Pending') echo 'selected'; ?>>Pending</option>
            </select>
            <button type="submit"><i class="fas fa-search"></i></button>
            <?php if($search || $filter !== 'All'): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="padding:10px; color:var(--accent);">Clear</a>
            <?php endif; ?>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                        <td>
                            <div style="font-weight:bold;"><?php echo htmlspecialchars($row['client_name']); ?></div>
                            <div style="font-size:12px; color:#9ca3af;"><?php echo htmlspecialchars($row['email']); ?></div>
                        </td>
                        <td><?php echo $row['invoice_date']; ?></td>
                        <td><?php echo $currency . number_format($row['amount'], 2); ?></td>
                        <td>
                            <?php if($row['status'] == 'Paid'): ?>
                                <span class="badge badge-paid">Paid</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button onclick="editInvoice(<?php echo htmlspecialchars(json_encode($row)); ?>)" class="btn-sm btn-edit"><i class="fas fa-edit"></i></button>
                             <button onclick="sendInvoiceEmail(<?php echo $row['id']; ?>)" class="btn-sm" style="background:#007bff;"><i class="fas fa-paper-plane"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this invoice?');">
                                <input type="hidden" name="invoice_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="delete_invoice" class="btn-sm btn-del"><i class="fas fa-trash"></i></button>
                            </form>

                            <?php if($row['status'] != 'Paid'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as Paid?');">
                                <input type="hidden" name="invoice_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="approve_invoice" class="btn-sm" style="background:#10b981;"><i class="fas fa-check"></i></button>
                                
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;">No invoices found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 3. CREATE/EDIT SECTION -->
    <div id="create" class="section">
        <h2 id="formTitle">Create New Invoice</h2>
        <br>
        <form method="POST" class="settings-form" style="max-width:800px;">
            <input type="hidden" name="invoice_id" id="invoice_id">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div class="form-group">
                    <label>Client Name</label>
                    <input type="text" name="client_name" id="client_name" required>
                </div>
                <div class="form-group">
                    <label>Client Email</label>
                    <input type="email" name="email" id="email" required>
                </div>
                <div class="form-group">
                    <label>Invoice Number</label>
                    <input type="text" name="invoice_number" id="invoice_number" required>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="invoice_date" id="invoice_date" required>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" step="0.01" name="amount" id="amount" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="Pending">Pending</option>
                        <option value="Paid">Paid</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="save_invoice" style="width:100%; margin-top:20px;">Save Invoice</button>
            <button type="button" onclick="resetForm()" style="width:100%; margin-top:10px; background:#6b7280;">Cancel / Clear</button>
        </form>
    </div>

    <!-- 4. SETTINGS SECTION -->
    <div id="settings" class="section">
        <h2>System Settings</h2>
        <br>
        <form method="POST" class="settings-form">
            <div class="form-group">
                <label>Company Name (Appears on Sidebar)</label>
                <input type="text" name="company_name" value="<?php echo htmlspecialchars($companyName); ?>">
            </div>
            <div class="form-group">
                <label>Currency Symbol</label>
                <select name="currency_symbol" style="width:100%;">
                    <option value="₱" <?php if($currency == '₱') echo 'selected'; ?>>₱ (PHP)</option>
                    <option value="$" <?php if($currency == '$') echo 'selected'; ?>>$ (USD)</option>
                    <option value="€" <?php if($currency == '€') echo 'selected'; ?>>€ (EUR)</option>
                </select>
            </div>
            <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="dark_mode" id="dm_check" style="width:auto;" <?php if($darkMode) echo 'checked'; ?>>
                <label for="dm_check" style="margin:0;">Enable Dark Mode</label>
            </div>
            <button type="submit" name="save_settings">Save Changes</button>
        </form>
    </div>

</div>

<script>
    // Navigation Logic
    function showSection(id) {
        document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        
        // Update Sidebar Active State
        document.querySelectorAll('.sidebar a').forEach(el => el.classList.remove('active-link'));
        document.getElementById('link-' + (id === 'create' ? 'create' : id)).classList.add('active-link');
    }
        function sendInvoiceEmail(invoiceId) {
            if (confirm('Are you sure you want to send this invoice to the client?')) {
                // Create a hidden form and submit it
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = 'send_invoice_email.php';
                form.style.display = 'none';
                
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'invoice_id';
                input.value = invoiceId;
                
                form.appendChild(input);
                document.body.appendChild(form);
                
                // Open response in new window to see result
                form.target = 'emailResponse';
                window.open('', 'emailResponse', 'width=400,height=200');
                
                form.submit();
                
                // Remove form after submission
                setTimeout(function() {
                    document.body.removeChild(form);
                }, 1000);
            }
                }
    // Edit Logic
    function editInvoice(data) {
        showSection('create');
        document.getElementById('formTitle').innerText = "Edit Invoice #" + data.invoice_number;
        document.getElementById('invoice_id').value = data.id;
        document.getElementById('client_name').value = data.client_name;
        document.getElementById('email').value = data.email;
        document.getElementById('invoice_number').value = data.invoice_number;
        document.getElementById('invoice_date').value = data.invoice_date;
        document.getElementById('amount').value = data.amount;
        document.getElementById('status').value = data.status;
    }

    function resetForm() {
        document.getElementById('formTitle').innerText = "Create New Invoice";
        document.getElementById('invoice_id').value = "";
        document.querySelector('form.settings-form').reset();
    }
    // Auto generate invoice number when email is entered
document.getElementById('email').addEventListener('blur', function () {

    let emailValue = this.value.trim();

    if (emailValue !== "") {

        fetch('generate_invoice_number.php')
            .then(response => response.text())
            .then(data => {
                document.getElementById('invoice_number').value = data;
            })
            .catch(error => console.error('Error:', error));

    }
});

    // Initialize Chart
    const ctx = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode($chartValues); ?>,
                backgroundColor: '#4f46e5',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, grid: { color: '<?php echo $darkMode ? "#374151" : "#e5e7eb"; ?>' } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // Handle URL parameters to keep state on refresh
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.has('search') || urlParams.has('filter')) {
        showSection('invoices');
    }
</script>

</body>
</html>
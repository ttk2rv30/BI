<?php
/**
 * index.php - Main Dashboard
 * * Features:
 * - Authentication Check
 * - Dynamic Charts (Chart.js)
 * - KPI Breakdown (Invoice, Cash Sale, etc.)
 * - Deep Dive Tables with Client-side Sorting
 * - Real-time Filtering & Search
 * - Data Timestamp Display
 */

require_once 'db_connect.php';

// 1. ตรวจสอบ Session (ถ้าไม่ได้ Login ให้ดีดไปหน้า login.php)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. ดึง Master Data สำหรับ Dropdown (Year, Dept, Warehouse)
try {
    // ดึงปีที่มีข้อมูล
    $years = $pdo->query("SELECT DISTINCT TxnYear FROM v_erp_clean_data ORDER BY TxnYear DESC")->fetchAll(PDO::FETCH_COLUMN);
    // ดึงแผนก
    $depts = $pdo->query("SELECT DISTINCT Department FROM v_erp_clean_data WHERE Department IS NOT NULL ORDER BY Department")->fetchAll(PDO::FETCH_COLUMN);
    // ดึงคลังสินค้า
    $whs   = $pdo->query("SELECT DISTINCT Warehouse FROM v_erp_clean_data WHERE Warehouse IS NOT NULL ORDER BY Warehouse")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // กรณี Database ยังไม่พร้อม หรือ View ยังไม่สร้าง
    $years = [date('Y')];
    $depts = [];
    $whs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Analytics Dashboard</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        :root {
            --bg-dark: #111827;
            --bg-sidebar: #0f172a;
            --bg-card: #1f2937;
            --bg-hover: #374151;
            --text-main: #f3f4f6;
            --text-sub: #9ca3af;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-blue: #3b82f6;
            --border-color: #374151;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-dark); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        /* --- Loader Animation --- */
        #loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(17, 24, 39, 0.9); z-index: 9999;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            visibility: hidden; opacity: 0; transition: opacity 0.3s;
        }
        #loader.active { visibility: visible; opacity: 1; }
        .spinner {
            width: 50px; height: 50px; border: 4px solid rgba(255,255,255,0.1);
            border-top: 4px solid var(--accent-green); border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 15px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* --- Layout --- */
        .sidebar {
            width: 280px; background: var(--bg-sidebar); padding: 20px;
            display: flex; flex-direction: column; border-right: 1px solid var(--border-color);
            overflow-y: auto; flex-shrink: 0;
            transition: transform 0.3s ease;
        }
        
        .main-content {
            flex-grow: 1; padding: 25px; overflow-y: auto;
            display: flex; flex-direction: column; gap: 20px;
        }

        /* --- Sidebar Elements --- */
        .brand {
            font-size: 1.4rem; font-weight: 700; color: var(--accent-green);
            margin-bottom: 20px; display: flex; flex-direction: column;
        }
        .user-info {
            font-size: 0.8rem; color: var(--text-sub); font-weight: normal; margin-top: 5px;
        }
        .filter-group { margin-bottom: 15px; }
        .filter-label {
            font-size: 0.75rem; color: var(--text-sub); margin-bottom: 6px;
            display: block; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .custom-input, .custom-select {
            width: 100%; background: var(--bg-hover); border: 1px solid var(--border-color);
            color: white; padding: 10px; border-radius: 6px; outline: none; font-size: 0.9rem;
        }
        .custom-input::placeholder { color: #6b7280; }
        .custom-input:focus, .custom-select:focus { border-color: var(--accent-green); }

        .btn-refresh {
            width: 100%; padding: 12px; background: var(--accent-green);
            border: none; border-radius: 6px; color: white; font-weight: 600;
            cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-refresh:hover { opacity: 0.9; }
        .btn-logout { background: var(--accent-red); margin-top: auto; }
        
        .last-update-text {
            font-size: 0.75rem; color: var(--text-sub); text-align: center; margin-top: 10px; font-style: italic;
        }

        /* --- KPI Cards --- */
        .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .kpi-card {
            background: var(--bg-card); padding: 20px; border-radius: 12px;
            border: 1px solid var(--border-color); min-height: 150px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .kpi-title { font-size: 0.9rem; color: var(--text-sub); text-transform: uppercase; }
        .kpi-value { font-size: 2rem; font-weight: 700; margin: 5px 0; color: #fff; }
        
        /* KPI Breakdown List */
        .kpi-breakdown {
            margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-color);
            font-size: 0.8rem; color: var(--text-sub);
        }
        .bd-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .bd-val { font-weight: 500; color: #d1d5db; }

        /* --- Charts --- */
        .chart-row { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; min-height: 350px; }
        .chart-card {
            background: var(--bg-card); padding: 20px; border-radius: 12px;
            border: 1px solid var(--border-color); display: flex; flex-direction: column;
        }
        .chart-header { font-size: 1rem; font-weight: 600; margin-bottom: 15px; color: var(--text-main); }
        .canvas-container { flex-grow: 1; position: relative; width: 100%; height: 100%; }

        /* --- Tables --- */
        .section-header { font-size: 1.1rem; font-weight: 600; margin-bottom: 10px; color: var(--accent-green); display: flex; align-items: center; gap: 8px; }
        .table-container {
            background: var(--bg-card); border-radius: 12px; overflow: hidden;
            border: 1px solid var(--border-color); margin-bottom: 30px;
        }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .data-table th, .data-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .data-table th {
            background: #111827; color: var(--text-sub); font-weight: 600; text-transform: uppercase;
            font-size: 0.75rem; cursor: pointer; user-select: none; transition: background 0.2s;
        }
        .data-table th:hover { background: var(--bg-hover); color: white; }
        .data-table th i { margin-left: 6px; font-size: 0.8em; opacity: 0.6; }
        .data-table tbody tr:hover { background: var(--bg-hover); }
        
        .text-right { text-align: right !important; }
        .val-green { color: var(--accent-green); }
        .val-red { color: var(--accent-red); }
        .val-blue { color: var(--accent-blue); }

        /* --- Responsive --- */
        @media (max-width: 1024px) {
            .kpi-grid, .chart-row { grid-template-columns: 1fr; }
            .sidebar { position: absolute; left: -280px; height: 100%; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(280px); left: 0; }
            .mobile-toggle { display: block; position: fixed; bottom: 20px; right: 20px; z-index: 101; background: var(--accent-green); padding: 15px; border-radius: 50%; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        }
    </style>
</head>
<body>

    <div id="loader">
        <div class="spinner"></div>
        <div style="color:white; font-weight:500;">Processing Data...</div>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="brand">
            <span><i class="fas fa-chart-line"></i> ERP Analytics</span>
            <div class="user-info">
                User: <?php echo htmlspecialchars($_SESSION['email'] ?? 'Guest'); ?>
                <br>Role: <?php echo htmlspecialchars($_SESSION['role'] ?? 'User'); ?>
            </div>
        </div>

        <div class="filter-group">
            <label class="filter-label">Search Product</label>
            <input type="text" id="inp_prod" class="custom-input" placeholder="Code, Name, Group...">
        </div>
        <div class="filter-group">
            <label class="filter-label">Search Customer</label>
            <input type="text" id="inp_cust" class="custom-input" placeholder="Customer Name...">
        </div>
        <div class="filter-group">
            <label class="filter-label">Search Salesperson</label>
            <input type="text" id="inp_sp" class="custom-input" placeholder="Salesperson Name...">
        </div>

        <div class="filter-group">
            <label class="filter-label">Year</label>
            <select id="sel_year" class="custom-select">
                <?php foreach($years as $y) echo "<option value='$y'>$y</option>"; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Month</label>
            <select id="sel_month" class="custom-select">
                <option value="All">All Months</option>
                <?php 
                for($m=1; $m<=12; $m++) {
                    $monthName = date('F', mktime(0, 0, 0, $m, 10));
                    echo "<option value='$m'>$monthName</option>";
                }
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Department</label>
            <select id="sel_dept" class="custom-select">
                <option value="All">All Departments</option>
                <?php foreach($depts as $d) echo "<option value='$d'>$d</option>"; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Warehouse</label>
            <select id="sel_wh" class="custom-select">
                <option value="All">All Warehouses</option>
                <?php foreach($whs as $w) echo "<option value='$w'>$w</option>"; ?>
            </select>
        </div>

        <button onclick="updateDashboard()" class="btn-refresh">
            <i class="fas fa-sync-alt"></i> Update View
        </button>
        
        <div class="last-update-text" id="txt_last_update">
            Data timestamp: Loading...
        </div>

        <form action="auth_action.php" method="POST" style="margin-top:auto;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn-refresh btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>

    <div class="main-content">
        
        <div class="kpi-grid">
            <div class="kpi-card" style="border-top: 4px solid #f87171;">
                <div>
                    <div class="kpi-title">Total Documents</div>
                    <div class="kpi-value" style="color:#f87171;" id="kpi_doc_total">0</div>
                </div>
                <div class="kpi-breakdown" id="kpi_doc_bd"></div>
            </div>

            <div class="kpi-card" style="border-top: 4px solid #10b981;">
                <div>
                    <div class="kpi-title">Total Revenue</div>
                    <div class="kpi-value" style="color:#10b981;" id="kpi_sale_total">0.00</div>
                </div>
                <div class="kpi-breakdown" id="kpi_sale_bd"></div>
            </div>

            <div class="kpi-card" style="border-top: 4px solid #a78bfa;">
                <div>
                    <div class="kpi-title">Total Quantity</div>
                    <div class="kpi-value" style="color:#a78bfa;" id="kpi_qty_total">0.00</div>
                </div>
                <div class="kpi-breakdown" id="kpi_qty_bd"></div>
            </div>
        </div>

        <div class="chart-row">
            <div class="chart-card">
                <div class="chart-header">Monthly Sales Trend</div>
                <div class="canvas-container">
                    <canvas id="chartTrend"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">Sales Team Share</div>
                <div class="canvas-container">
                    <canvas id="chartTeam"></canvas>
                </div>
            </div>
        </div>

        <div>
            <div class="section-header"><i class="fas fa-user-tie"></i> Salesperson Analysis</div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('sp', 'Salesperson')">Name <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('sp', 'TotalRevenue')">Revenue <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('sp', 'TotalDiscount')">Discount <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('sp', 'CustomerCount')">Customers (Count) <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('sp', 'TotalQty')">Qty <i class="fas fa-sort"></i></th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sp"></tbody>
                </table>
            </div>
        </div>

        <div>
            <div class="section-header"><i class="fas fa-building"></i> Customer Insights (Top 100)</div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('cust', 'Customer')">Customer <i class="fas fa-sort"></i></th>
                            <th>Sales Team</th>
                            <th class="text-right" onclick="sortTable('cust', 'TotalRevenue')">Revenue <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('cust', 'TotalDiscount')">Discount <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('cust', 'TotalQty')">Qty <i class="fas fa-sort"></i></th>
                        </tr>
                    </thead>
                    <tbody id="tbody_cust"></tbody>
                </table>
            </div>
        </div>
    <div style="margin-top: 30px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
            <h3 style="color:var(--accent-blue); margin-bottom: 20px;">
                <i class="fas fa-boxes"></i> Product Performance Analysis
            </h3>
        </div>

        <div>
            <div class="section-header"><i class="fas fa-layer-group"></i> Product Group Overview</div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('group', 'ProductGroup')">Group Name <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('group', 'TotalRevenue')">Revenue <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('group', 'TotalQty')">Qty <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('group', 'CustomerCount')">Unique Customers <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('group', 'SalesCount')">Active Sales <i class="fas fa-sort"></i></th>
                        </tr>
                    </thead>
                    <tbody id="tbody_group"></tbody>
                </table>
            </div>
        </div>

        <div>
            <div class="section-header"><i class="fas fa-tag"></i> Top Items Performance (Top 100)</div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('item', 'ProductCode')">Item Code <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable('item', 'ProductName')">Description <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('item', 'TotalRevenue')">Revenue <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('item', 'TotalQty')">Qty <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('item', 'CustomerCount')">Unique Customers <i class="fas fa-sort"></i></th>
                            <th class="text-right" onclick="sortTable('item', 'SalesCount')">Active Sales <i class="fas fa-sort"></i></th>
                        </tr>
                    </thead>
                    <tbody id="tbody_item"></tbody>
                </table>
            </div>
        </div>
    </div>


<script>
    // ==========================================
    // 1. Global Variables & Configuration
    // ==========================================
    
    // Chart Instances
    let trendChart, teamChart;
    
    // Raw Data Storage (สำหรับ Client-side Sorting)
    let rawDataSP = [];
    let rawDataCust = [];
    let rawDataGroup = [];
    let rawDataItem = [];
    
    // Sorting State
    let sortState = { key: 'TotalRevenue', dir: 'desc' };

    // Config Chart.js Defaults for Dark Theme
    Chart.defaults.color = '#9ca3af';
    Chart.defaults.borderColor = '#374151';
    Chart.defaults.font.family = "'Inter', sans-serif";

    // ==========================================
    // 2. Initialization
    // ==========================================
    document.addEventListener('DOMContentLoaded', () => {
        initCharts();      // เตรียมกราฟเปล่า
        updateDashboard(); // ดึงข้อมูลครั้งแรก
    });

    // Toggle Loader Overlay
    function showLoader(show) {
        const loader = document.getElementById('loader');
        if(show) loader.classList.add('active');
        else loader.classList.remove('active');
    }

    // Initialize Charts
    function initCharts() {
        // 1. Trend Line Chart
        const ctxTrend = document.getElementById('chartTrend').getContext('2d');
        trendChart = new Chart(ctxTrend, {
            type: 'line',
            data: { 
                labels: [], 
                datasets: [{ 
                    label: 'Sales', 
                    data: [], 
                    borderColor: '#10b981', 
                    backgroundColor: 'rgba(16, 185, 129, 0.1)', 
                    tension: 0.3, 
                    fill: true,
                    pointBackgroundColor: '#10b981'
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } },
                scales: { 
                    y: { grid: { color: '#374151' }, ticks: { callback: function(value) { return numFmt(value, 'compact'); } } }, 
                    x: { grid: { display: false } } 
                },
                interaction: { mode: 'index', intersect: false }
            }
        });

        // 2. Team Doughnut Chart
        const ctxTeam = document.getElementById('chartTeam').getContext('2d');
        teamChart = new Chart(ctxTeam, {
            type: 'doughnut',
            data: { 
                labels: [], 
                datasets: [{ 
                    data: [], 
                    backgroundColor: ['#10b981', '#3b82f6', '#f87171', '#fbbf24', '#a78bfa'], 
                    borderWidth: 0 
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                cutout: '70%',
                plugins: { 
                    legend: { position: 'right', labels: { color: '#9ca3af', boxWidth: 12, padding: 15 } } 
                }
            }
        });
    }

    // ==========================================
    // 3. Main Data Fetching Function
    // ==========================================
    async function updateDashboard() {
        showLoader(true);
        
        // รวบรวมค่าจาก Filter และ Search Input ทั้งหมด
        const params = new URLSearchParams({
            action: 'dashboard',
            year: document.getElementById('sel_year').value,
            month: document.getElementById('sel_month').value,
            dept: document.getElementById('sel_dept').value,
            wh: document.getElementById('sel_wh').value,
            sp: document.getElementById('inp_sp').value,
            cust: document.getElementById('inp_cust').value,
            search: document.getElementById('inp_prod').value
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            if (!res.ok) throw new Error("API Error: " + res.status);
            const data = await res.json();

            // 3.1 Update Timestamp
            document.getElementById('txt_last_update').innerHTML = 
                `<i class="fas fa-clock"></i> Data Updated: ${data.last_updated || 'Unknown'}`;

            // 3.2 Render KPI Cards
            renderKPI(data.kpi_breakdown);

            // 3.3 Update Charts
            // Trend
            trendChart.data.labels = data.trend.labels;
            trendChart.data.datasets[0].data = data.trend.data;
            trendChart.update();
            // Team
            teamChart.data.labels = data.salespersons.map(s => s.Salesperson);
            teamChart.data.datasets[0].data = data.salespersons.map(s => s.Sales);
            teamChart.update();

            // 3.4 Store Data for Tables & Sort
            rawDataSP = data.analysis_sp;
            rawDataCust = data.analysis_cust;
            rawDataGroup = data.analysis_group; // New
            rawDataItem = data.analysis_item;   // New
            
            // Default Sort (Revenue DESC)
            sortData(rawDataSP, 'TotalRevenue', 'desc');
            sortData(rawDataCust, 'TotalRevenue', 'desc');
            sortData(rawDataGroup, 'TotalRevenue', 'desc');
            sortData(rawDataItem, 'TotalRevenue', 'desc');
            
            // Render All Tables
            renderTableSP();
            renderTableCust();
            renderTableGroup();
            renderTableItem();

        } catch (err) {
            console.error("Error fetching data:", err);
            alert("Unable to load data. Please try again.");
        } finally {
            showLoader(false);
        }
    }

    // ==========================================
    // 4. Interaction & Drill-down Logic
    // ==========================================
    
    // ฟังก์ชันเปิดหน้า Detail (Drill-down)
    function openDetail(type, value) {
        // ดึงค่า Filter ปัจจุบัน
        const year = document.getElementById('sel_year').value;
        const month = document.getElementById('sel_month').value;
        const dept = document.getElementById('sel_dept').value;
        const wh = document.getElementById('sel_wh').value;
        const searchSP = document.getElementById('inp_sp').value;
        const searchCust = document.getElementById('inp_cust').value;

        let url = `detail.php?year=${year}&month=${month}&dept=${dept}&wh=${wh}&sp=${searchSP}&cust=${searchCust}`;
        
        // เพิ่ม Filter เฉพาะเจาะจง
        if (type === 'salesperson') {
            url += `&filter_sp=${encodeURIComponent(value)}`;
        } else if (type === 'customer') {
            url += `&filter_cust=${encodeURIComponent(value)}`;
        } else if (type === 'group') {
            url += `&filter_group=${encodeURIComponent(value)}`;
        } else if (type === 'item') {
            url += `&filter_item=${encodeURIComponent(value)}`;
        }
        
        // เปิด Tab ใหม่
        window.open(url, '_blank');
    }

    // ==========================================
    // 5. Sorting Logic
    // ==========================================
    function sortTable(tableType, key) {
        // Toggle Direction
        if (sortState.key === key) {
            sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
        } else {
            sortState.key = key;
            sortState.dir = 'desc';
        }

        // Apply Sort & Re-render based on table type
        switch(tableType) {
            case 'sp':
                sortData(rawDataSP, key, sortState.dir);
                renderTableSP();
                break;
            case 'cust':
                sortData(rawDataCust, key, sortState.dir);
                renderTableCust();
                break;
            case 'group':
                sortData(rawDataGroup, key, sortState.dir);
                renderTableGroup();
                break;
            case 'item':
                sortData(rawDataItem, key, sortState.dir);
                renderTableItem();
                break;
        }
    }

    function sortData(arr, key, dir) {
        arr.sort((a, b) => {
            let valA = a[key];
            let valB = b[key];
            
            // Try parse number
            let numA = parseFloat(valA);
            let numB = parseFloat(valB);
            
            if(!isNaN(numA) && !isNaN(numB)) {
                valA = numA; valB = numB;
            } else {
                valA = String(valA).toLowerCase();
                valB = String(valB).toLowerCase();
            }

            if (valA < valB) return dir === 'asc' ? -1 : 1;
            if (valA > valB) return dir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    // ==========================================
    // 6. Rendering Functions (Tables & KPIs)
    // ==========================================

    function renderKPI(breakdown) {
        let tDoc = 0, tSale = 0, tQty = 0;
        let hDoc = '', hSale = '', hQty = '';

        if(breakdown && breakdown.length > 0) {
            breakdown.forEach(item => {
                const type = item.Type || 'Other';
                tDoc += Number(item.Documents);
                tSale += Number(item.Sales);
                tQty += Number(item.Quantity);

                hDoc += `<div class="bd-row"><span>${type}</span><span class="bd-val">${numFmt(item.Documents, 'int')}</span></div>`;
                hSale += `<div class="bd-row"><span>${type}</span><span class="bd-val">${numFmt(item.Sales, 'float')}</span></div>`;
                hQty += `<div class="bd-row"><span>${type}</span><span class="bd-val">${numFmt(item.Quantity, 'float')}</span></div>`;
            });
        }

        document.getElementById('kpi_doc_total').innerText = numFmt(tDoc, 'int');
        document.getElementById('kpi_sale_total').innerText = numFmt(tSale, 'float');
        document.getElementById('kpi_qty_total').innerText = numFmt(tQty, 'float');

        document.getElementById('kpi_doc_bd').innerHTML = hDoc;
        document.getElementById('kpi_sale_bd').innerHTML = hSale;
        document.getElementById('kpi_qty_bd').innerHTML = hQty;
    }

    // --- Table 1: Salesperson ---
    function renderTableSP() {
        const tbody = document.getElementById('tbody_sp');
        if(rawDataSP.length === 0) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No Data Found</td></tr>'; return; }
        
        tbody.innerHTML = rawDataSP.map(row => `
            <tr>
                <td style="cursor:pointer;" onclick="openDetail('salesperson', '${row.Salesperson}')">
                    <strong style="color:white; text-decoration:underline;">${row.Salesperson}</strong>
                    <i class="fas fa-external-link-alt" style="font-size:0.7em; color:#6b7280; margin-left:5px;"></i>
                </td>
                <td class="text-right val-green">${numFmt(row.TotalRevenue, 'float')}</td>
                <td class="text-right val-red">${numFmt(row.TotalDiscount, 'float')}</td>
                <td class="text-right">${numFmt(row.CustomerCount, 'int')}</td>
                <td class="text-right val-blue">${numFmt(row.TotalQty, 'float')}</td>
            </tr>
        `).join('');
    }

    // --- Table 2: Customer ---
    function renderTableCust() {
        const tbody = document.getElementById('tbody_cust');
        if(rawDataCust.length === 0) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No Data Found</td></tr>'; return; }

        tbody.innerHTML = rawDataCust.map(row => `
            <tr>
                <td style="cursor:pointer;" onclick="openDetail('customer', '${row.Customer}')">
                    <strong style="color:white; text-decoration:underline;">${row.Customer}</strong>
                    <i class="fas fa-external-link-alt" style="font-size:0.7em; color:#6b7280; margin-left:5px;"></i>
                </td>
                <td style="font-size:0.8rem; color:#9ca3af">${row.SalesTeam}</td>
                <td class="text-right val-green">${numFmt(row.TotalRevenue, 'float')}</td>
                <td class="text-right val-red">${numFmt(row.TotalDiscount, 'float')}</td>
                <td class="text-right val-blue">${numFmt(row.TotalQty, 'float')}</td>
            </tr>
        `).join('');
    }

    // --- Table 3: Product Group ---
    function renderTableGroup() {
        const tbody = document.getElementById('tbody_group');
        if(rawDataGroup.length === 0) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No Data Found</td></tr>'; return; }
        
        tbody.innerHTML = rawDataGroup.map(row => `
            <tr>
                <td style="cursor:pointer;" onclick="openDetail('group', '${row.ProductGroup}')">
                    <strong style="color:white; text-decoration:underline;">${row.ProductGroup}</strong>
                    <i class="fas fa-external-link-alt" style="font-size:0.7em; color:#6b7280; margin-left:5px;"></i>
                </td>
                <td class="text-right val-green">${numFmt(row.TotalRevenue, 'float')}</td>
                <td class="text-right val-blue">${numFmt(row.TotalQty, 'float')}</td>
                <td class="text-right">${numFmt(row.CustomerCount, 'int')}</td>
                <td class="text-right">${numFmt(row.SalesCount, 'int')}</td>
            </tr>
        `).join('');
    }

    // --- Table 4: Top Items ---
    function renderTableItem() {
        const tbody = document.getElementById('tbody_item');
        if(rawDataItem.length === 0) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No Data Found</td></tr>'; return; }
        
        tbody.innerHTML = rawDataItem.map(row => `
            <tr>
                <td style="cursor:pointer;" onclick="openDetail('item', '${row.ProductCode}')">
                    <strong style="color:white; text-decoration:underline;">${row.ProductCode}</strong>
                    <i class="fas fa-external-link-alt" style="font-size:0.7em; color:#6b7280; margin-left:5px;"></i>
                </td>
                <td>${row.ProductName}</td>
                <td class="text-right val-green">${numFmt(row.TotalRevenue, 'float')}</td>
                <td class="text-right val-blue">${numFmt(row.TotalQty, 'float')}</td>
                <td class="text-right">${numFmt(row.CustomerCount, 'int')}</td>
                <td class="text-right">${numFmt(row.SalesCount, 'int')}</td>
            </tr>
        `).join('');
    }

    // ==========================================
    // 7. Helper Utilities
    // ==========================================
    
    // Numeric Formatter
    function numFmt(val, type = 'float') {
        const num = Number(val);
        if(isNaN(num)) return '0';
        
        if (type === 'compact') {
            return Intl.NumberFormat('en-US', { notation: "compact", maximumFractionDigits: 1 }).format(num);
        } else if (type === 'int') {
            return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        } else {
            // float
            return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

</script>
</body>
</html>

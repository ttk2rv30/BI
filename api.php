<?php
// api.php
header('Content-Type: application/json');
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit;
}

// --- 1. Helper: สร้าง SQL Condition ตามสิทธิ์ User ---
function getUserPermissionClause($pdo, $userId) {
    // ดึง Role และ Config ของ User
    $stmt = $pdo->prepare("SELECT role, sales_name, allowed_depts, allowed_warehouses FROM BI_Users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $clause = "";
    $params = [];

    if ($user['role'] === 'ADMIN') {
        // Super User: ดูได้หมด ไม่ต้องกรอง
        return ['sql' => '', 'params' => []];
    } 
    elseif (!empty($user['sales_name'])) {
        // Salesperson: ดูได้เฉพาะชื่อตัวเอง
        $clause .= " AND Salesperson = ?";
        $params[] = $user['sales_name'];
    } 
    else {
        // Manager หรือ User อื่นๆ: กรองตาม Department / Warehouse
        if (!empty($user['allowed_depts'])) {
            $depts = json_decode($user['allowed_depts'], true);
            if (!empty($depts)) {
                $placeholders = implode(',', array_fill(0, count($depts), '?'));
                $clause .= " AND Department IN ($placeholders)";
                $params = array_merge($params, $depts);
            }
        }
        if (!empty($user['allowed_warehouses'])) {
            $whs = json_decode($user['allowed_warehouses'], true);
            if (!empty($whs)) {
                $placeholders = implode(',', array_fill(0, count($whs), '?'));
                $clause .= " AND Warehouse IN ($placeholders)";
                $params = array_merge($params, $whs);
            }
        }
    }

    return ['sql' => $clause, 'params' => $params];
}

// --- 2. เตรียม Parameter พื้นฐาน ---
$action = $_GET['action'] ?? 'dashboard'; // dashboard หรือ get_details
$year   = $_GET['year'] ?? date('Y');
$month  = $_GET['month'] ?? 'All';
$dept   = $_GET['dept'] ?? 'All';
$wh     = $_GET['wh'] ?? 'All';
$sp     = $_GET['sp'] ?? '';
$cust   = $_GET['cust'] ?? '';
$prod   = $_GET['search'] ?? '';

// สร้าง Base WHERE Clause (จาก Filter หน้าเว็บ)
$conditions = ["TxnYear = ?"];
$baseParams = [$year];

if ($month != 'All') { $conditions[] = "TxnMonth = ?"; $baseParams[] = $month; }
if ($dept != 'All')  { $conditions[] = "Department = ?"; $baseParams[] = $dept; }
if ($wh != 'All')    { $conditions[] = "Warehouse = ?"; $baseParams[] = $wh; }
if (!empty($sp))     { $conditions[] = "Salesperson LIKE ?"; $baseParams[] = "%$sp%"; }
if (!empty($cust))   { $conditions[] = "Customer LIKE ?"; $baseParams[] = "%$cust%"; }
if (!empty($prod))   { 
    $conditions[] = "(ProductGroup LIKE ? OR ProductCode LIKE ? OR ProductName LIKE ?)"; 
    array_push($baseParams, "%$prod%", "%$prod%", "%$prod%");
}

// --- 3. ผสาน Permission Clause เข้ากับ Base WHERE ---
$perm = getUserPermissionClause($pdo, $_SESSION['user_id']);
$whereSql = "WHERE " . implode(" AND ", $conditions) . $perm['sql'];
$finalParams = array_merge($baseParams, $perm['params']);


// ==========================================
// CASE A: Dashboard Data (Aggregation)
// ==========================================
if ($action === 'dashboard') {
    
    // Last Update
    $sqlTime = "SELECT MAX(`timestamp`) FROM ERPPBI";
    $lastUpdate = $pdo->query($sqlTime)->fetchColumn();

    // KPI Breakdown
    $sqlKPI = "SELECT `Transaction_Type` as Type, COUNT(DISTINCT `Document_Number`) as Documents, 
               SUM(TotalAmount) as Sales, SUM(Qty) as Quantity
               FROM v_erp_clean_data $whereSql GROUP BY `Transaction_Type`";
    $stmt = $pdo->prepare($sqlKPI); $stmt->execute($finalParams); 
    $kpiData = $stmt->fetchAll();

    // Trend
    $sqlTrend = "SELECT TxnMonth, SUM(TotalAmount) as Sales FROM v_erp_clean_data $whereSql GROUP BY TxnMonth ORDER BY TxnMonth";
    $stmt = $pdo->prepare($sqlTrend); $stmt->execute($finalParams); 
    $trendData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $finalTrend = []; $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    for($i=1; $i<=12; $i++){ $finalTrend['labels'][] = $months[$i-1]; $finalTrend['data'][] = $trendData[$i] ?? 0; }

    // Top Lists
    $stmt = $pdo->prepare("SELECT Salesperson, SUM(TotalAmount) as Sales FROM v_erp_clean_data $whereSql GROUP BY Salesperson ORDER BY Sales DESC LIMIT 5");
    $stmt->execute($finalParams); $topTeam = $stmt->fetchAll();

    // Deep Dive Tables
    $sqlAnalyzeSP = "SELECT Salesperson, SUM(TotalAmount) as TotalRevenue, SUM(DiscountAmount) as TotalDiscount, 
                     COUNT(DISTINCT Customer) as CustomerCount, SUM(Qty) as TotalQty
                     FROM v_erp_clean_data $whereSql GROUP BY Salesperson ORDER BY TotalRevenue DESC";
    $stmt = $pdo->prepare($sqlAnalyzeSP); $stmt->execute($finalParams); $analyzeSP = $stmt->fetchAll();

    $sqlAnalyzeCust = "SELECT Customer, SUM(TotalAmount) as TotalRevenue, SUM(DiscountAmount) as TotalDiscount, 
                       SUM(Qty) as TotalQty, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT Salesperson SEPARATOR ', '), ', ', 3) as SalesTeam
                       FROM v_erp_clean_data $whereSql GROUP BY Customer ORDER BY TotalRevenue DESC LIMIT 100";
    $stmt = $pdo->prepare($sqlAnalyzeCust); $stmt->execute($finalParams); $analyzeCust = $stmt->fetchAll();

    $sqlGroup = "SELECT 
                        ProductGroup, 
                        SUM(Qty) as TotalQty, 
                        SUM(TotalAmount) as TotalRevenue, 
                        COUNT(DISTINCT Customer) as CustomerCount, 
                        COUNT(DISTINCT Salesperson) as SalesCount
                    FROM v_erp_clean_data $whereSql 
                    GROUP BY ProductGroup 
                    ORDER BY TotalRevenue DESC";
        $stmt = $pdo->prepare($sqlGroup); 
        $stmt->execute($finalParams); 
        $analyzeGroup = $stmt->fetchAll();

    // --- 2. เพิ่ม: Product Item Analysis (Top 100) ---
        $sqlItem = "SELECT 
                        ProductCode, 
                        ProductName,
                        ProductGroup, 
                        SUM(Qty) as TotalQty, 
                        SUM(TotalAmount) as TotalRevenue, 
                        COUNT(DISTINCT Customer) as CustomerCount, 
                        COUNT(DISTINCT Salesperson) as SalesCount
                    FROM v_erp_clean_data $whereSql 
                    GROUP BY ProductCode, ProductName 
                    ORDER BY TotalRevenue DESC 
                    LIMIT 100";
        $stmt = $pdo->prepare($sqlItem); 
        $stmt->execute($finalParams); 
        $analyzeItem = $stmt->fetchAll();


        echo json_encode([
                'last_updated' => $lastUpdate,
                'kpi_breakdown' => $kpiData,
                'trend' => $finalTrend,
                'salespersons' => $topTeam, // กราฟวงกลม
                'analysis_sp' => $analyzeSP,
                'analysis_cust' => $analyzeCust,
                // ส่งข้อมูลชุดใหม่ไป
                'analysis_group' => $analyzeGroup,
                'analysis_item' => $analyzeItem
            ]);

// ==========================================
// CASE B: Detail Data (Drill-down with Pagination)
// ==========================================
} elseif ($action === 'get_details') {
    
    // รับ Parameters เพิ่มเติมสำหรับการ Click Drill-down
    $filter_sp   = $_GET['filter_sp'] ?? '';
    $filter_cust = $_GET['filter_cust'] ?? '';
    $filter_group = $_GET['filter_group'] ?? ''; // รับชื่อกลุ่มสินค้า
    $filter_item  = $_GET['filter_item'] ?? '';  // รับรหัสสินค้า
    
    // Pagination Params
    $page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    // เพิ่มเงื่อนไข Drill-down เข้าไปใน WHERE Clause
    if (!empty($filter_sp)) {
        $whereSql .= " AND Salesperson = ?";
        $finalParams[] = $filter_sp;
    }
    if (!empty($filter_cust)) {
        $whereSql .= " AND Customer = ?";
        $finalParams[] = $filter_cust;
    }

    // --- เพิ่ม Logic กรองสินค้า ---
    if (!empty($filter_group)) {
        $whereSql .= " AND ProductGroup = ?";
        $finalParams[] = $filter_group;
    }
    if (!empty($filter_item)) {
        $whereSql .= " AND ProductCode = ?";
        $finalParams[] = $filter_item;
    }

    // 1. นับจำนวนทั้งหมด (Total Count)
    $countSql = "SELECT COUNT(*) FROM v_erp_clean_data $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($finalParams);
    $totalRecords = $stmt->fetchColumn();

    // 2. ดึงข้อมูลจริง (Data Fetch)
    $dataSql = "SELECT 
                    TxnDate, `Document_Number`, Customer, Salesperson, 
                    ProductCode, ProductName, Qty, UOM, UnitPrice, TotalAmount, 
                    DiscountAmount, Department, Warehouse
                FROM v_erp_clean_data 
                $whereSql 
                ORDER BY TxnDate DESC, `Document_Number` DESC
                LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($dataSql);
    $stmt->execute($finalParams);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'total' => $totalRecords,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($totalRecords / $limit),
        'data' => $rows
    ]);
}
?>

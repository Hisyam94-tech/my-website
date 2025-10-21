<?php
// Enhanced API with Fixed Balance Transaction Recording
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
$host = 'localhost';
$dbname = 'TE_Fiona_Db';
$username = 'tesvr';
$password = 'Abcd@1234';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Route handler
switch($action) {
    case 'getAll':
        getAllItems();
        break;
    case 'getById':
        getItemById();
        break;
    case 'add':
        addItem();
        break;
    case 'update':
        updateItem();
        break;
    case 'delete':
        deleteItem();
        break;
    case 'stockIn':
        stockMovement('in');
        break;
    case 'stockOut':
        stockMovement('out');
        break;
    case 'search':
        searchItems();
        break;
    case 'getLowStock':
        getLowStockItems();
        break;
    case 'getStats':
        getStats();
        break;
    case 'getHistory':
        getItemHistory();
        break;
    case 'reconcile':
        reconcileInventory();
        break;
    case 'validateIntegrity':
        validateIntegrity();
        break;
    case 'checkMismatches':
        checkMismatches();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

// ========================================
// STOCK MOVEMENT - FIXED VERSION
// ========================================

function stockMovement($type) {
    global $pdo;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id']) || empty($data['quantity']) || empty($data['requestor'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current item with row lock
        $stmt = $pdo->prepare("SELECT * FROM PE_Inventory WHERE ID = ? FOR UPDATE");
        $stmt->execute([$data['id']]);
        $current = $stmt->fetch();
        
        if (!$current) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }
        
        $currentBalance = intval($current['BalanceQty']);
        $currentStoreIn = intval($current['StoreInQty']);
        $currentStoreOut = intval($current['StoreOutQty']);
        $qty = intval($data['quantity']);
        
        $now = new DateTime();
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        
        // Calculate new values
        if ($type === 'out') {
            if ($qty > $currentBalance) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Insufficient stock. Current balance: ' . $currentBalance]);
                return;
            }
            
            $newBalance = $currentBalance - $qty;
            $newStoreOut = $currentStoreOut + $qty;
            $movementType = 'OUT';
            
        } else { // stock in
            $newBalance = $currentBalance + $qty;
            $newStoreIn = $currentStoreIn + $qty;
            $movementType = 'IN';
        }
        
        // Log transaction FIRST with correct balances
        logTransaction($pdo, $data['id'], [
            'date' => $date,
            'time' => $time,
            'partNo' => $current['PartNumber'],
            'itemDescription' => $current['ItemDesc'],
            'bin' => $current['BIN'],
            'movementType' => $movementType,
            'quantity' => $qty,
            'balanceBefore' => $currentBalance,
            'balanceAfter' => $newBalance,
            'requestBy' => $data['requestor'],
            'prepareBy' => $type === 'in' ? $data['requestor'] : $current['PrepareBy'],
            'purpose' => $data['purpose'] ?? ($type === 'in' ? 'Stock in' : 'Stock out'),
            'remarkSN' => $current['RemarkSN'] ?? ''
        ]);
        
        // Update main inventory table
        if ($type === 'out') {
            $stmt = $pdo->prepare("
                UPDATE PE_Inventory 
                SET StoreOutQty = ?,
                    BalanceQty = ?,
                    DATE = ?,
                    TIME = ?,
                    RequestBy = ?
                WHERE ID = ?
            ");
            $stmt->execute([$newStoreOut, $newBalance, $date, $time, $data['requestor'], $data['id']]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE PE_Inventory 
                SET StoreInQty = ?,
                    BalanceQty = ?,
                    DATE = ?,
                    TIME = ?,
                    PrepareBy = ?
                WHERE ID = ?
            ");
            $stmt->execute([$newStoreIn, $newBalance, $date, $time, $data['requestor'], $data['id']]);
        }
        
        // Verify the update was correct
        $stmt = $pdo->prepare("SELECT StoreInQty, StoreOutQty, BalanceQty FROM PE_Inventory WHERE ID = ?");
        $stmt->execute([$data['id']]);
        $updated = $stmt->fetch();
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => ucfirst($type) . ' movement completed successfully',
            'newBalance' => intval($updated['BalanceQty']),
            'totalIn' => intval($updated['StoreInQty']),
            'totalOut' => intval($updated['StoreOutQty']),
            'movement' => [
                'type' => $movementType,
                'quantity' => $qty,
                'balanceBefore' => $currentBalance,
                'balanceAfter' => intval($updated['BalanceQty'])
            ]
        ]);
    } catch(PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to process stock movement: ' . $e->getMessage()]);
    }
}

// ========================================
// RECONCILIATION FUNCTIONS
// ========================================

function reconcileInventory() {
    global $pdo;
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    
    try {
        $pdo->beginTransaction();
        
        if ($id) {
            // Reconcile single item
            $result = reconcileInventoryBalances($pdo, $id);
            $message = "Item ID $id reconciled successfully";
            $details = $result;
        } else {
            // Reconcile all items
            $stmt = $pdo->query("SELECT ID FROM PE_Inventory");
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $results = [];
            foreach ($ids as $itemId) {
                $results[$itemId] = reconcileInventoryBalances($pdo, $itemId);
            }
            $message = "All " . count($ids) . " items reconciled successfully";
            $details = $results;
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'details' => $details
        ]);
    } catch(PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Reconciliation failed: ' . $e->getMessage()]);
    }
}

function reconcileInventoryBalances($pdo, $inventoryId) {
    // Calculate totals from logs
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN MovementType IN ('IN', 'INITIAL') THEN Quantity ELSE 0 END), 0) AS TotalIn,
            COALESCE(SUM(CASE WHEN MovementType = 'OUT' THEN Quantity ELSE 0 END), 0) AS TotalOut
        FROM PE_Inventory_Logs
        WHERE InventoryID = ?
    ");
    $stmt->execute([$inventoryId]);
    $result = $stmt->fetch();
    
    $totalIn = intval($result['TotalIn']);
    $totalOut = intval($result['TotalOut']);
    $balance = $totalIn - $totalOut;
    
    // Update main inventory - using simple UPDATE without CURRENT_TIMESTAMP function
    $stmt = $pdo->prepare("
        UPDATE PE_Inventory
        SET 
            StoreInQty = ?,
            StoreOutQty = ?,
            BalanceQty = ?
        WHERE ID = ?
    ");
    $stmt->execute([$totalIn, $totalOut, $balance, $inventoryId]);
    
    return [
        'totalIn' => $totalIn,
        'totalOut' => $totalOut,
        'balance' => $balance
    ];
}

function validateIntegrity() {
    global $pdo;
    
    try {
        // Check for log calculation errors
        $stmt = $pdo->query("
            SELECT 
                LogID,
                InventoryID,
                PartNumber,
                MovementType,
                Quantity,
                BalanceBefore,
                BalanceAfter,
                CASE 
                    WHEN MovementType IN ('IN', 'INITIAL') 
                    THEN BalanceBefore + Quantity
                    WHEN MovementType = 'OUT'
                    THEN BalanceBefore - Quantity
                    ELSE BalanceBefore
                END AS ExpectedBalanceAfter
            FROM PE_Inventory_Logs
            HAVING BalanceAfter != ExpectedBalanceAfter
        ");
        
        $logErrors = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'logErrors' => $logErrors,
            'errorCount' => count($logErrors),
            'status' => count($logErrors) === 0 ? 'ALL_VALID' : 'ERRORS_FOUND'
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Validation failed: ' . $e->getMessage()]);
    }
}

function checkMismatches() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                i.ID,
                i.PartNumber,
                i.BIN,
                i.BalanceQty AS RecordedBalance,
                i.StoreInQty AS RecordedStoreIn,
                i.StoreOutQty AS RecordedStoreOut,
                COALESCE(SUM(CASE WHEN l.MovementType IN ('IN', 'INITIAL') THEN l.Quantity ELSE 0 END), 0) AS CalculatedStoreIn,
                COALESCE(SUM(CASE WHEN l.MovementType = 'OUT' THEN l.Quantity ELSE 0 END), 0) AS CalculatedStoreOut,
                COALESCE(SUM(CASE WHEN l.MovementType IN ('IN', 'INITIAL') THEN l.Quantity ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN l.MovementType = 'OUT' THEN l.Quantity ELSE 0 END), 0) AS CalculatedBalance
            FROM PE_Inventory i
            LEFT JOIN PE_Inventory_Logs l ON i.ID = l.InventoryID
            GROUP BY i.ID
            HAVING RecordedBalance != CalculatedBalance 
                OR RecordedStoreIn != CalculatedStoreIn
                OR RecordedStoreOut != CalculatedStoreOut
        ");
        
        $mismatches = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'mismatches' => $mismatches,
            'mismatchCount' => count($mismatches),
            'status' => count($mismatches) === 0 ? 'ALL_BALANCED' : 'MISMATCHES_FOUND'
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Mismatch check failed: ' . $e->getMessage()]);
    }
}

// ========================================
// CRUD FUNCTIONS
// ========================================

function getAllItems() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT * FROM PE_Inventory ORDER BY DATE DESC, TIME DESC");
        $items = $stmt->fetchAll();
        
        $formattedItems = array_map(function($item) {
            return formatItem($item);
        }, $items);
        
        echo json_encode(['success' => true, 'data' => $formattedItems]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch items']);
    }
}

function getItemById() {
    global $pdo;
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM PE_Inventory WHERE ID = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        
        if ($item) {
            // Get calculated values from logs
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN MovementType IN ('IN', 'INITIAL') THEN Quantity ELSE 0 END), 0) AS TotalIn,
                    COALESCE(SUM(CASE WHEN MovementType = 'OUT' THEN Quantity ELSE 0 END), 0) AS TotalOut
                FROM PE_Inventory_Logs
                WHERE InventoryID = ?
            ");
            $stmt->execute([$id]);
            $calculated = $stmt->fetch();
            
            $calcBalance = intval($calculated['TotalIn']) - intval($calculated['TotalOut']);
            
            $response = formatItem($item);
            $response['calculated'] = [
                'totalIn' => intval($calculated['TotalIn']),
                'totalOut' => intval($calculated['TotalOut']),
                'balance' => $calcBalance
            ];
            $response['isBalanced'] = ($item['BalanceQty'] == $calcBalance);
            
            echo json_encode(['success' => true, 'data' => $response]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch item']);
    }
}

function addItem() {
    global $pdo;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['partNo']) || empty($data['itemDescription']) || empty($data['bin']) || empty($data['prepareBy'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        $balanceQty = intval($data['balanceQty'] ?? 0);
        $now = new DateTime();
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');
        
        // Insert into main inventory
        $stmt = $pdo->prepare("
            INSERT INTO PE_Inventory 
            (DATE, PartNumber, ItemDesc, TIME, BIN, StoreInQty, StoreOutQty, BalanceQty, RequestBy, PrepareBy, RemarkSN) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $date,
            $data['partNo'],
            $data['itemDescription'],
            $time,
            $data['bin'],
            $balanceQty,
            0,
            $balanceQty,
            $data['requestBy'] ?? '',
            $data['prepareBy'],
            $data['remarkSN'] ?? ''
        ]);
        
        $newId = $pdo->lastInsertId();
        
        // Create initial log entry if quantity > 0
        if ($balanceQty > 0) {
            logTransaction($pdo, $newId, [
                'date' => $date,
                'time' => $time,
                'partNo' => $data['partNo'],
                'itemDescription' => $data['itemDescription'],
                'bin' => $data['bin'],
                'movementType' => 'INITIAL',
                'quantity' => $balanceQty,
                'balanceBefore' => 0,
                'balanceAfter' => $balanceQty,
                'requestBy' => $data['requestBy'] ?? '',
                'prepareBy' => $data['prepareBy'],
                'purpose' => 'Initial stock entry',
                'remarkSN' => $data['remarkSN'] ?? ''
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Item added successfully',
            'id' => $newId,
            'initialBalance' => $balanceQty
        ]);
    } catch(PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add item: ' . $e->getMessage()]);
    }
}

function updateItem() {
    global $pdo;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing item ID']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current state
        $stmt = $pdo->prepare("SELECT * FROM PE_Inventory WHERE ID = ?");
        $stmt->execute([$data['id']]);
        $current = $stmt->fetch();
        
        if (!$current) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }
        
        // Update basic info (not quantities - those come from stock movements)
        $stmt = $pdo->prepare("
            UPDATE PE_Inventory 
            SET PartNumber = ?, ItemDesc = ?, BIN = ?, PrepareBy = ?, RemarkSN = ?
            WHERE ID = ?
        ");
        
        $stmt->execute([
            $data['partNo'] ?? $current['PartNumber'],
            $data['itemDescription'] ?? $current['ItemDesc'],
            $data['bin'] ?? $current['BIN'],
            $data['prepareBy'] ?? $current['PrepareBy'],
            $data['remarkSN'] ?? $current['RemarkSN'],
            $data['id']
        ]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
    } catch(PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update item: ' . $e->getMessage()]);
    }
}

function deleteItem() {
    global $pdo;
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete logs first
        $stmt = $pdo->prepare("DELETE FROM PE_Inventory_Logs WHERE InventoryID = ?");
        $stmt->execute([$id]);
        
        // Delete inventory item
        $stmt = $pdo->prepare("DELETE FROM PE_Inventory WHERE ID = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Item deleted successfully']);
    } catch(PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete item']);
    }
}

function searchItems() {
    global $pdo;
    $term = $_GET['term'] ?? '';
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM PE_Inventory 
            WHERE PartNumber LIKE ? 
            OR ItemDesc LIKE ? 
            OR BIN LIKE ? 
            OR PrepareBy LIKE ?
            OR RequestBy LIKE ?
            ORDER BY DATE DESC, TIME DESC
        ");
        
        $searchTerm = "%$term%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $items = $stmt->fetchAll();
        
        $formattedItems = array_map(function($item) {
            return formatItem($item);
        }, $items);
        
        echo json_encode(['success' => true, 'data' => $formattedItems]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Search failed']);
    }
}

function getLowStockItems() {
    global $pdo;
    $threshold = filter_var($_GET['threshold'] ?? 10, FILTER_VALIDATE_INT);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM PE_Inventory WHERE BalanceQty <= ? ORDER BY BalanceQty ASC");
        $stmt->execute([$threshold]);
        $items = $stmt->fetchAll();
        
        $formattedItems = array_map(function($item) {
            return formatItem($item);
        }, $items);
        
        echo json_encode(['success' => true, 'data' => $formattedItems]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch low stock items']);
    }
}

function getStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Total unique items
        $stmt = $pdo->query("SELECT COUNT(DISTINCT PartNumber) as total FROM PE_Inventory");
        $stats['totalItems'] = intval($stmt->fetch()['total']);
        
        // Total stores
        $stmt = $pdo->query("SELECT COUNT(DISTINCT BIN) as total FROM PE_Inventory");
        $stats['totalStores'] = intval($stmt->fetch()['total']);
        
        // Total quantity
        $stmt = $pdo->query("SELECT SUM(BalanceQty) as total FROM PE_Inventory");
        $stats['totalQuantity'] = intval($stmt->fetch()['total'] ?? 0);
        
        // Low stock items
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM PE_Inventory WHERE BalanceQty <= 10");
        $stats['lowStock'] = intval($stmt->fetch()['total']);
        
        // Total movements
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM PE_Inventory_Logs");
        $stats['totalMovements'] = intval($stmt->fetch()['total']);
        
        // Check for mismatches
        $stmt = $pdo->query("
            SELECT COUNT(*) as total
            FROM PE_Inventory i
            LEFT JOIN (
                SELECT 
                    InventoryID,
                    COALESCE(SUM(CASE WHEN MovementType IN ('IN', 'INITIAL') THEN Quantity ELSE 0 END), 0) - 
                    COALESCE(SUM(CASE WHEN MovementType = 'OUT' THEN Quantity ELSE 0 END), 0) AS CalcBalance
                FROM PE_Inventory_Logs
                GROUP BY InventoryID
            ) l ON i.ID = l.InventoryID
            WHERE i.BalanceQty != COALESCE(l.CalcBalance, 0)
        ");
        $stats['mismatches'] = intval($stmt->fetch()['total']);
        
        echo json_encode(['success' => true, 'data' => $stats]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch statistics']);
    }
}

function getItemHistory() {
    global $pdo;
    $partNo = $_GET['partNo'] ?? '';
    
    if (empty($partNo)) {
        http_response_code(400);
        echo json_encode(['error' => 'Part number required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM PE_Inventory_Logs 
            WHERE PartNumber = ? 
            ORDER BY DATE DESC, TIME DESC, LogID DESC
        ");
        $stmt->execute([$partNo]);
        $logs = $stmt->fetchAll();
        
        $formattedLogs = array_map(function($log) {
            return [
                'logId' => intval($log['LogID']),
                'inventoryId' => intval($log['InventoryID']),
                'date' => $log['DATE'],
                'time' => $log['TIME'],
                'partNo' => $log['PartNumber'],
                'itemDescription' => $log['ItemDesc'],
                'bin' => $log['BIN'],
                'movementType' => $log['MovementType'],
                'quantity' => intval($log['Quantity']),
                'balanceBefore' => intval($log['BalanceBefore']),
                'balanceAfter' => intval($log['BalanceAfter']),
                'requestBy' => $log['RequestBy'],
                'prepareBy' => $log['PrepareBy'],
                'purpose' => $log['Purpose'],
                'remarkSN' => $log['RemarkSN'],
                'createdDateTime' => $log['CreatedDateTime']
            ];
        }, $logs);
        
        echo json_encode(['success' => true, 'data' => $formattedLogs]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch item history']);
    }
}

// ========================================
// HELPER FUNCTIONS
// ========================================

function logTransaction($pdo, $inventoryId, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO PE_Inventory_Logs 
        (InventoryID, DATE, TIME, PartNumber, ItemDesc, BIN, MovementType, Quantity, 
         BalanceBefore, BalanceAfter, RequestBy, PrepareBy, Purpose, RemarkSN) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $inventoryId,
        $data['date'],
        $data['time'],
        $data['partNo'],
        $data['itemDescription'],
        $data['bin'],
        $data['movementType'],
        $data['quantity'],
        $data['balanceBefore'],
        $data['balanceAfter'],
        $data['requestBy'],
        $data['prepareBy'],
        $data['purpose'] ?? '',
        $data['remarkSN'] ?? ''
    ]);
}

function formatItem($item) {
    return [
        'id' => intval($item['ID']),
        'date' => $item['DATE'],
        'partNo' => $item['PartNumber'],
        'itemDescription' => $item['ItemDesc'],
        'time' => $item['TIME'],
        'bin' => $item['BIN'],
        'storeInQty' => intval($item['StoreInQty']),
        'storeOutQty' => intval($item['StoreOutQty']),
        'balanceQty' => intval($item['BalanceQty']),
        'requestBy' => $item['RequestBy'],
        'prepareBy' => $item['PrepareBy'],
        'remarkSN' => $item['RemarkSN'],
        'updateDateTime' => $item['UpdateDateTime']
    ];
}
?>
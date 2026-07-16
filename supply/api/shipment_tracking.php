<?php
// Suppress error display and ensure clean output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unwanted output
ob_start();

// Include only database config (not full config.php which requires session)
require_once __DIR__ . '/../config/database.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

// Handle GET request for fetching GPS location
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['shipment_id'])) {
    $shipment_id = intval($_GET['shipment_id']);
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("
        SELECT 
            current_latitude,
            current_longitude,
            estimated_arrival,
            last_location_update,
            (SELECT speed_kmh FROM shipment_locations 
             WHERE shipment_id = ? 
             ORDER BY recorded_at DESC LIMIT 1) as current_speed,
            (SELECT heading_degrees FROM shipment_locations 
             WHERE shipment_id = ? 
             ORDER BY recorded_at DESC LIMIT 1) as current_heading
        FROM shipments
        WHERE id = ? AND status = 'in_transit'
    ");
    $stmt->bind_param("iii", $shipment_id, $shipment_id, $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $gps_data = $result->fetch_assoc();
    $stmt->close();
    closeDBConnection($conn);
    
    if ($gps_data && $gps_data['current_latitude'] && $gps_data['current_longitude']) {
        echo json_encode($gps_data);
    } else {
        echo json_encode(['error' => 'No GPS data available']);
    }
    exit;
}

// Only allow POST requests for location updates
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['shipment_id']) || !isset($input['latitude']) || !isset($input['longitude'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: shipment_id, latitude, longitude']);
    exit;
}

$shipment_id = intval($input['shipment_id']);
$latitude = floatval($input['latitude']);
$longitude = floatval($input['longitude']);
$speed = isset($input['speed']) ? floatval($input['speed']) : null;
$heading = isset($input['heading']) ? intval($input['heading']) : null;
$accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : null;

// Validate coordinates
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates']);
    exit;
}

$conn = getDBConnection();

try {
    // Verify shipment exists and is in transit
    $stmt = $conn->prepare("SELECT id, status FROM shipments WHERE id = ?");
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $shipment = $result->fetch_assoc();
    $stmt->close();
    
    if (!$shipment) {
        http_response_code(404);
        echo json_encode(['error' => 'Shipment not found']);
        exit;
    }
    
    // Only track shipments that are in transit
    if ($shipment['status'] !== 'in_transit') {
        http_response_code(400);
        echo json_encode(['error' => 'Shipment is not in transit']);
        exit;
    }
    
    // Insert location record
    $stmt = $conn->prepare("
        INSERT INTO shipment_locations 
        (shipment_id, latitude, longitude, speed_kmh, heading_degrees, accuracy_meters)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("idddii", $shipment_id, $latitude, $longitude, $speed, $heading, $accuracy);
    $stmt->execute();
    $location_id = $conn->insert_id;
    $stmt->close();
    
    // Update current location in shipments table
    $stmt = $conn->prepare("
        UPDATE shipments 
        SET current_latitude = ?, 
            current_longitude = ?, 
            last_location_update = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddi", $latitude, $longitude, $shipment_id);
    $stmt->execute();
    $stmt->close();
    
    // Calculate and update ETA if destination is known
    $stmt = $conn->prepare("
        SELECT 
            s.destination_warehouse_id,
            s.supplier_id,
            s.type,
            w.latitude as dest_lat,
            w.longitude as dest_lng
        FROM shipments s
        LEFT JOIN warehouses w ON (
            s.type = 'inbound' AND w.id = s.destination_warehouse_id
        )
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $shipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $route = $result->fetch_assoc();
    $stmt->close();
    
    if ($route && $route['dest_lat'] && $route['dest_lng']) {
        // Calculate distance and ETA using OSRM (same as route planning)
        $osrmUrl = "https://router.project-osrm.org/route/v1/driving/{$longitude},{$latitude};{$route['dest_lng']},{$route['dest_lat']}?overview=false";
        $response = @file_get_contents($osrmUrl);
        
        if ($response) {
            $routeData = json_decode($response, true);
            if ($routeData['code'] === 'Ok' && isset($routeData['routes'][0])) {
                $duration = $routeData['routes'][0]['duration']; // in seconds
                $eta = date('Y-m-d H:i:s', time() + $duration);
                
                $stmt = $conn->prepare("UPDATE shipments SET estimated_arrival = ? WHERE id = ?");
                $stmt->bind_param("si", $eta, $shipment_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    closeDBConnection($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Location updated successfully',
        'location_id' => $location_id
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        closeDBConnection($conn);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Error $e) {
    if (isset($conn)) {
        closeDBConnection($conn);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>


<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
include 'connect.php';

// Enable error reporting untuk debug
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan display error ke user

// Check connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Ambil filter
$postType = isset($_GET['postType']) ? trim($_GET['postType']) : 'all';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$state = isset($_GET['state']) ? trim($_GET['state']) : 'all';

// Build query
$sql = "SELECT 
            p.PetID, 
            p.Name, 
            p.Type, 
            p.Breed, 
            p.Age, 
            p.Gender, 
            p.Image, 
            p.Status,
            p.PostType,
            p.State, 
            p.District, 
            p.Latitude, 
            p.Longitude,
            p.Price,
            p.SitStartDate,
            p.SitEndDate
        FROM pet p
        WHERE p.ApprovalStatus = 'approved' 
        AND p.Latitude IS NOT NULL 
        AND p.Longitude IS NOT NULL
        AND p.Latitude != 0 
        AND p.Longitude != 0";

$params = [];
$types = "";

// Apply filters
if ($postType !== 'all') {
    if ($postType === 'Adoption') {
        $sql .= " AND p.PostType = 'Adopt'";
    } elseif ($postType === 'Pet Sitting') {
        $sql .= " AND p.PostType = 'Pet Sit'";
    }
}

if ($type !== 'all') {
    $sql .= " AND p.Type LIKE ?";
    $params[] = '%' . $type . '%';
    $types .= "s";
}

if ($status !== 'all') {
    $sql .= " AND p.Status LIKE ?";
    $params[] = '%' . $status . '%';
    $types .= "s";
}

if ($state !== 'all') {
    $sql .= " AND p.State LIKE ?";
    $params[] = '%' . $state . '%';
    $types .= "s";
}

$sql .= " ORDER BY p.PostDate DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL prepare failed', 'details' => $conn->error]);
    exit();
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL execute failed', 'details' => $stmt->error]);
    exit();
}

$result = $stmt->get_result();

$pets = [];
$count = 0;

/**
 * Helper function untuk standardize case
 */
function standardizeCase($string) {
    if (empty($string)) return '';
    
    $string = trim($string);
    
    // Special cases untuk kata yang perlu uppercase semua
    $upperCases = ['UK', 'USA', 'UAE', 'DNA', 'GPS'];
    
    // Check jika string adalah singkatan
    if (strtoupper($string) === $string && strlen($string) <= 4) {
        return $string;
    }
    
    // Check jika ada dalam list special cases
    foreach ($upperCases as $uc) {
        if (strtoupper($string) === $uc) {
            return $uc;
        }
    }
    
    // Standardize to title case untuk kata biasa
    return ucwords(strtolower($string));
}

while ($row = $result->fetch_assoc()) {
    $count++;
    
    // Format PostType
    $displayPostType = ($row['PostType'] === 'Adopt') ? 'Adoption' : 'Pet Sitting';
    
    // Standardize case untuk type, status, state
    $petType = standardizeCase($row['Type']);
    $petStatus = standardizeCase($row['Status']);
    $petState = standardizeCase($row['State']);
    $petBreed = standardizeCase($row['Breed']);
    $petDistrict = standardizeCase($row['District']);
    
    // Format price
    $displayPrice = '';
    if ($row['Price'] > 0) {
        $displayPrice = 'RM ' . number_format($row['Price'], 2);
        if ($row['PostType'] === 'Pet Sit') {
            $displayPrice .= '/day';
        }
    }
    
    // Format date range
    $dateRange = '';
    if ($row['SitStartDate'] && $row['SitEndDate']) {
        $start = date('d M Y', strtotime($row['SitStartDate']));
        $end = date('d M Y', strtotime($row['SitEndDate']));
        $dateRange = $start . ' - ' . $end;
    }
    
    $pets[] = [
        'PetID' => (int)$row['PetID'],
        'Name' => $row['Name'],
        'Type' => $petType, // Standardized
        'Breed' => $petBreed, // Standardized
        'Age' => $row['Age'],
        'Gender' => $row['Gender'],
        'Image' => $row['Image'],
        'Status' => $petStatus, // Standardized
        'PostType' => $displayPostType,
        'State' => $petState, // Standardized
        'District' => $petDistrict, // Standardized
        'Latitude' => (float)$row['Latitude'],
        'Longitude' => (float)$row['Longitude'],
        'DisplayPrice' => $displayPrice,
        'DateRange' => $dateRange
    ];
}

$stmt->close();
$conn->close();

echo json_encode($pets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<?php
$servername = "localhost:3301";
$username = "root";
$password = "";
$dbname = "furcare";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

date_default_timezone_set('Asia/Kuala_Lumpur');

// AUTO-UPDATE OVERDUE PET SITTING LISTINGS  //
function updateOverduePetListings($conn)
{
    $currentDate = date('Y-m-d');

    $update_sql = "UPDATE pet 
                   SET Status = 'Overdue' 
                   WHERE PostType = 'Pet Sit' 
                   AND SitStartDate < ?  
                   AND Status = 'Available'  
                   AND NOT EXISTS (
                       SELECT 1 FROM petsitrequest 
                       WHERE petsitrequest.PetID = pet.PetID 
                       AND petsitrequest.Status IN ('approved', 'pending', 'completed')
                   )";

    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("s", $currentDate);
    return $stmt->execute() ? $stmt->affected_rows : 0;
}

// UPDATE OVERDUE PET SIT REQUESTS //
function updateOverduePetSitRequests($conn) {
    $update_sql = "UPDATE petsitrequest 
                   SET Status = 'overdue' 
                   WHERE Status = 'pending' 
                   AND StartDate < CURDATE()";
    
    $stmt = $conn->prepare($update_sql);
    return $stmt->execute() ? $stmt->affected_rows : 0;
}

// RUN AUTO-UPDATES //
if (rand(1, 2) === 1) {
    $updatedListings = updateOverduePetListings($conn);
    $updatedRequests = updateOverduePetSitRequests($conn);
    
    // Optional: Log updates
    if ($updatedListings > 0 || $updatedRequests > 0) {
        error_log(date('Y-m-d H:i:s') . " - Updated $updatedListings listings and $updatedRequests requests to overdue", 3, "overdue_updates.log");
    }
}
?>
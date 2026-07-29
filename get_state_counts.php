<?php
// get_state_counts.php
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

$sql = "SELECT TRIM(State) AS state, COUNT(*) AS cnt
        FROM pet
        WHERE State IS NOT NULL AND State <> ''
        GROUP BY TRIM(State)";

$res = $conn->query($sql);
$out = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $key = mb_strtolower($r['state']);
        $out[$key] = (int)$r['cnt'];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

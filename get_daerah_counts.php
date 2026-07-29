<?php
// get_daerah_counts.php
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

$sql = "SELECT TRIM(District) AS district, COUNT(*) AS cnt
        FROM pet
        WHERE District IS NOT NULL AND District <> ''
        GROUP BY TRIM(District)";

$res = $conn->query($sql);
$out = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $out[$r['district']] = (int)$r['cnt'];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
